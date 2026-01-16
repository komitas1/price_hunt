<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\UserState;
use App\Models\User;
use App\Models\PriceAlert;

class TelegramController extends Controller
{
    public function handle(Request $request)
    {
        $chatId = $request->input('message.chat.id');
        $message = $request->input('message.text');
        $callback = $request->input('callback_query');

        // Получаем состояние пользователя
        $state = UserState::firstOrCreate(
            ['tg_user_id' => $chatId],
            ['step' => 'start', 'data' => json_encode([])]
        );

        // ==== CALLBACK (inline кнопки выбора города) ====
        if ($callback) {
            $code = $callback['data'];
            $city = collect($this->getCities())->firstWhere('code', $code);
            if (!$city) return;

            $data = json_decode($state->data, true);
            if ($state->step === 'from') {
                $data['from'] = $city['code'];
                $data['from_name'] = $city['name'];
                $state->step = 'to';
                $state->data = json_encode($data);
                $state->save();
                $this->sendMessage($chatId, "Вы выбрали: {$city['name']} ({$city['code']})\nКуда летим?");
            } elseif ($state->step === 'to') {
                $data['to'] = $city['code'];
                $data['to_name'] = $city['name'];
                $state->step = 'date';
                $state->data = json_encode($data);
                $state->save();
                $this->sendMessage($chatId, "Введите дату вылета (YYYY-MM-DD)");
            }
            return;
        }

        // ==== /start ====
        if ($message === '/start') {
            $this->sendMessageWithButtons($chatId, "Привет! Что делаем?", [
                [['text' => 'Поиск билетов']]
            ]);
            $state->step = 'waiting_action';
            $state->save();
            return;
        }

        // ==== Шаг 1: выбрать действие ====
        if ($state->step === 'waiting_action' && $message === 'Поиск билетов') {
            $state->step = 'from';
            $state->save();
            $this->sendMessage($chatId, "Откуда летим? (можно написать полностью, например: Москва)");
            return;
        }

        // ==== Шаг 2: Откуда ====
        if ($state->step === 'from') {
            $matches = $this->findCity($message);
            if (empty($matches)) {
                $this->sendMessage($chatId, "❌ Город не найден. Попробуйте ещё раз.");
                return;
            }

            if (count($matches) === 1) {
                $city = $matches[0];
                $data = json_decode($state->data, true);
                $data['from'] = $city['code'];
                $data['from_name'] = $city['name'];
                $state->step = 'to';
                $state->data = json_encode($data);
                $state->save();

                $this->sendMessage($chatId, "Вы выбрали: {$city['name']} ({$city['code']})\nКуда летим?");
                return;
            }

            // Несколько совпадений
            $keyboard = [];
            foreach ($matches as $c) {
                $keyboard[] = [['text' => "{$c['name']} ({$c['code']})", 'callback_data' => $c['code']]];
            }
            $this->sendMessageWithInlineButtons($chatId, "Найдено несколько совпадений, выберите:", $keyboard);
            return;
        }

        // ==== Шаг 3: Куда ====
        if ($state->step === 'to') {
            $matches = $this->findCity($message);
            if (empty($matches)) {
                $this->sendMessage($chatId, "❌ Город не найден. Попробуйте ещё раз.");
                return;
            }

            if (count($matches) === 1) {
                $city = $matches[0];
                $data = json_decode($state->data, true);
                $data['to'] = $city['code'];
                $data['to_name'] = $city['name'];
                $state->step = 'date';
                $state->data = json_encode($data);
                $state->save();
                $this->sendMessage($chatId, "Введите дату вылета (YYYY-MM-DD)");
                return;
            }

            // Несколько совпадений
            $keyboard = [];
            foreach ($matches as $c) {
                $keyboard[] = [['text' => "{$c['name']} ({$c['code']})", 'callback_data' => $c['code']]];
            }
            $this->sendMessageWithInlineButtons($chatId, "Найдено несколько совпадений, выберите:", $keyboard);
            return;
        }

        // ==== Шаг 4: Дата ====
        if ($state->step === 'date') {
            $data = json_decode($state->data, true);
            $data['date'] = $message;
            $state->step = 'passenger_filter';
            $state->data = json_encode($data);
            $state->save();

            $this->sendMessage($chatId, "Введите данные пассажиров и класс перелёта:\nПример: Y 2 0 1\nY — Эконом, 2 — взрослых, 0 — детей, 1 — младенцев");
            return;
        }

        // ==== Шаг 5: Пассажиры + класс ====
        if ($state->step === 'passenger_filter') {
            $parts = explode(' ', $message);
            if (count($parts) !== 4) {
                $this->sendMessage($chatId, "❌ Некорректный формат. Пример ввода: Y 2 0 1");
                return;
            }

            [$tripClass, $adults, $children, $infants] = $parts;

            if (!in_array(strtoupper($tripClass), ['Y','C','F','W'])) {
                $this->sendMessage($chatId, "❌ Класс перелёта указан неверно. Доступные: Y, C, F, W");
                return;
            }

            $data = json_decode($state->data, true);
            $data['trip_class'] = strtoupper($tripClass);
            $data['adults'] = max(1, (int)$adults);
            $data['children'] = max(0, (int)$children);
            $data['infants'] = max(0, (int)$infants);

            // ==== Freemium/Premium проверка ====
            $user = User::firstOrCreate(['tg_user_id' => $chatId], ['plan' => 'free']);
            $activeAlerts = PriceAlert::where('tg_user_id', $chatId)->where('is_active', true)->count();
            $maxSlots = $user->plan === 'premium' ? 50 : 2;
            if ($activeAlerts >= $maxSlots) {
                $this->sendMessage($chatId, "❌ Вы достигли лимита слотов ({$maxSlots}). Для увеличения перейдите на премиум.");
                return;
            }

            // Создаём PriceAlert
            PriceAlert::create([
                'tg_user_id' => $chatId,
                'origin' => $data['from'],
                'destination' => $data['to'],
                'depart_date' => $data['date'],
                'passengers' => $data['adults'],
                'current_price' => null,
                'target_price' => null,
                'is_active' => true
            ]);

            $state->step = 'search';
            $state->data = json_encode($data);
            $state->save();

            $tickets = $this->searchTickets(
                $data['from'],
                $data['to'],
                $data['date'],
                $data['adults'],
                $data['children'],
                $data['infants'],
                $data['trip_class']
            );

            if (!$tickets) {
                $this->sendMessage($chatId, "❌ Билеты не найдены");
            } else {
                $text = "✈ {$data['from_name']} → {$data['to_name']}\nДата: {$data['date']}\nПассажиры: {$data['adults']} взрослых, {$data['children']} детей, {$data['infants']} младенцев\nКласс: {$data['trip_class']}\n\n";
                foreach(array_slice($tickets,0,3) as $i => $t) {
                    $text .= ($i+1).". {$t['airline']} — {$t['price']} ₽\nВылет: {$t['departure_at']}\nКупить: {$t['link']}\n\n";
                }
                $this->sendMessage($chatId, $text);
            }

            $state->delete();
            return;
        }

        $this->sendMessage($chatId, "❌ Неверный ввод. Нажмите /start");
    }

    // ==== Пример метода поиска билетов через Travelpayouts ====
    private function searchTickets($from, $to, $date, $adults, $children, $infants, $tripClass)
    {
        $marker = env('TRAVELPAYOUTS_MARKER');
        $token = env('TRAVELPAYOUTS_API_TOKEN');
        $realHost = env('TRAVELPAYOUTS_REAL_HOST');
        $userIp = env('TRAVELPAYOUTS_USER_IP');

        $searchParams = [
            'trip_class' => $tripClass,
            'passengers' => [
                'adults' => $adults,
                'children' => $children,
                'infants' => $infants
            ],
            'directions' => [
                [
                    'origin' => strtoupper($from),
                    'destination' => strtoupper($to),
                    'date' => $date
                ]
            ]
        ];

        $signatureParams = [
            'adults' => $adults,
            'date' => $date,
            'destination' => strtoupper($to),
            'marker' => $marker,
            'origin' => strtoupper($from)
        ];
        ksort($signatureParams);
        $signature = md5(implode(':', $signatureParams) . ':' . $token);

        $response = Http::withHeaders([
            'x-affiliate-user-id' => $token,
            'x-real-host' => $realHost,
            'x-user-ip' => $userIp,
            'x-signature' => $signature,
            'Content-Type' => 'application/json'
        ])->post('https://tickets-api.travelpayouts.com/search/affiliate/start', [
            'marker' => $marker,
            'locale' => 'ru',
            'currency_code' => 'RUB',
            'market_code' => 'RU',
            'search_params' => $searchParams,
            'signature' => $signature
        ]);

        if (!$response->ok()) return null;
        $searchId = $response->json('search_id');

        sleep(3);

        $results = Http::withHeaders([
            'x-affiliate-user-id' => $token,
            'x-real-host' => $realHost
        ])->get("https://tickets-api.travelpayouts.com/search/affiliate/results/$searchId");

        return $results->json('tickets');
    }
}
