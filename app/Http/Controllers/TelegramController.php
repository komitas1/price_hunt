<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Models\UserState;
use App\Models\User;
use App\Models\PriceAlert;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    public function handle(Request $request)
    {
        Log::info('Telegram update', $request->all());

        $chatId = $request->input('message.chat.id')
            ?? $request->input('callback_query.message.chat.id');

        $message = $request->input('message.text');
        $callback = $request->input('callback_query');

        if (!$chatId) {
            Log::warning('No chat_id in update');
            return;
        }

        $state = UserState::firstOrCreate(
            ['tg_user_id' => $chatId],
            ['step' => 'start', 'data' => json_encode([])]
        );

        Log::info('User state', [
            'chat_id' => $chatId,
            'step' => $state->step,
            'data' => $state->data
        ]);

        // ===== CALLBACK =====
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

                $this->sendMessage($chatId, "Откуда: {$city['name']}\nКуда летим?");
                return;
            }

            if ($state->step === 'to') {
                $data['to'] = $city['code'];
                $data['to_name'] = $city['name'];
                $state->step = 'date';
                $state->data = json_encode($data);
                $state->save();

                $this->sendMessage($chatId, "Введите дату (YYYY-MM-DD)");
                return;
            }
        }

        // ===== /start =====
        if ($message === '/start') {
            $state->step = 'from';
            $state->data = json_encode([]);
            $state->save();

            $this->sendMessage($chatId, "Привет 👋\nОткуда летим?");
            return;
        }

        // ===== FROM =====
        if ($state->step === 'from') {
            $matches = $this->findCity($message);

            if (!$matches) {
                $this->sendMessage($chatId, "❌ Город не найден");
                return;
            }

            if (count($matches) === 1) {
                $city = $matches[0];
                $data = ['from' => $city['code'], 'from_name' => $city['name']];
                $state->step = 'to';
                $state->data = json_encode($data);
                $state->save();

                $this->sendMessage($chatId, "Откуда: {$city['name']}\nКуда летим?");
                return;
            }

            $keyboard = [];
            foreach ($matches as $c) {
                $keyboard[] = [[
                    'text' => "{$c['name']} ({$c['code']})",
                    'callback_data' => $c['code']
                ]];
            }

            $this->sendMessageWithInlineButtons($chatId, "Выберите город:", $keyboard);
            return;
        }

        // ===== TO =====
        if ($state->step === 'to') {
            $matches = $this->findCity($message);

            if (!$matches) {
                $this->sendMessage($chatId, "❌ Город не найден");
                return;
            }

            $data = json_decode($state->data, true);
            $city = $matches[0];

            $data['to'] = $city['code'];
            $data['to_name'] = $city['name'];

            $state->step = 'date';
            $state->data = json_encode($data);
            $state->save();

            $this->sendMessage($chatId, "Введите дату вылета (YYYY-MM-DD)");
            return;
        }

        // ===== DATE =====
        if ($state->step === 'date') {
            $data = json_decode($state->data, true);
            $data['date'] = $message;

            $state->step = 'passengers';
            $state->data = json_encode($data);
            $state->save();

            $this->sendMessage($chatId, "Формат:\nY 1 0 0\nY — эконом\n1 — взрослые");
            return;
        }

        // ===== PASSENGERS =====
        if ($state->step === 'passengers') {
            [$class, $adults, $children, $infants] = explode(' ', $message . '   ');

            $data = json_decode($state->data, true);
            $data['trip_class'] = strtoupper($class ?: 'Y');
            $data['adults'] = max(1, (int)$adults);
            $data['children'] = (int)$children;
            $data['infants'] = (int)$infants;

            $this->sendMessage($chatId, "🔍 Ищу билеты...");

            $tickets = $this->searchTickets($data);

            if (!$tickets) {
                $this->sendMessage($chatId, "❌ Билеты не найдены или API вернул ошибку");
                return;
            }

            $text = "✈ {$data['from_name']} → {$data['to_name']}\nДата: {$data['date']}\n\n";
            foreach (array_slice($tickets, 0, 3) as $i => $t) {
                $text .= ($i+1).". {$t['airline']} — {$t['price']} ₽\n{$t['link']}\n\n";
            }

            $this->sendMessage($chatId, $text);

            $state->delete();
            return;
        }

        $this->sendMessage($chatId, "Введите /start");
    }

    // =======================
    // TRAVELPAYOUTS
    // =======================
    private function searchTickets(array $data)
    {
        $marker = env('TRAVELPAYOUTS_MARKER');
        $token = env('TRAVELPAYOUTS_API_TOKEN');
        $host = env('TRAVELPAYOUTS_REAL_HOST');
        $ip = env('TRAVELPAYOUTS_USER_IP');
        $locale = 'ru';

        // Подпись для API — теперь с детьми, младенцами и классом
        $signatureParams = [
            'adults' => $data['adults'],
            'children' => $data['children'],
            'date' => $data['date'],
            'destination' => $data['to'],
            'infants' => $data['infants'],
            'locale' => $locale,
            'marker' => $marker,
            'origin' => $data['from'],
            'trip_class' => $data['trip_class']
        ];
        ksort($signatureParams);
        $signature = md5(implode(':', $signatureParams) . ':' . $token);

//        Log::info('TP signature', $signatureParams);

        $start = Http::withHeaders([
            'x-affiliate-user-id' => $token,
            'x-real-host' => $host,
            'x-user-ip' => $ip,
            'x-signature' => $signature,
            'User-Agent' => 'PriceHuntBot/1.0; locale=' . $locale,
            'Content-Type' => 'application/json'
        ])->post('https://tickets-api.travelpayouts.com/search/affiliate/start', [
            'marker' => $marker,
            'locale' => $locale,
            'currency_code' => 'RUB',
            'market_code' => 'RU',
            'search_params' => [
                'trip_class' => $data['trip_class'],
                'passengers' => [
                    'adults' => $data['adults'],
                    'children' => $data['children'],
                    'infants' => $data['infants'],
                ],
                'directions' => [[
                    'origin' => strtoupper($data['from']),
                    'destination' => strtoupper($data['to']),
                    'date' => $data['date']
                ]]
            ],
            'signature' => $signature
        ]);

        Log::info('TP start response', $start->json());

        if (!$start->ok() || !isset($start->json()['search_id'])) return null;

        $searchId = $start->json('search_id');

        // Подождать генерацию результатов
        sleep(3);

        $results = Http::withHeaders([
            'x-affiliate-user-id' => $token,
            'x-real-host' => $host,
            'User-Agent' => 'PriceHuntBot/1.0; locale=' . $locale
        ])->get("https://tickets-api.travelpayouts.com/search/affiliate/results/{$searchId}");

        Log::info('TP results', $results->json());

        return $results->json('tickets') ?? [];
    }


    private function sendMessage($chatId, $text)
    {
        Http::post("https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN') . "/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ]);
    }
    private function sendMessageWithInlineButtons($chatId, $text, array $buttons)
    {
        Http::post("https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN') . "/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ]);
    }
    private function getCities(): array
    {
        return Cache::remember('tp_cities', 86400, function () {
            return Http::get(
                'https://api.travelpayouts.com/data/ru/cities.json'
            )->json() ?? [];
        });
    }
    private function findCity(string $query): array
    {
        $query = mb_strtolower(trim($query));

        if ($query === '') {
            return [];
        }

        $cities = $this->getCities();
        $results = [];

        foreach ($cities as $city) {
            if (
                empty($city['name']) ||
                empty($city['code'])
            ) {
                continue;
            }

            $name = mb_strtolower($city['name']);
            $code = mb_strtolower($city['code']);

            // 1️⃣ Точное совпадение по IATA (MOW, IST)
            if ($code === $query) {
                return [[
                    'name' => $city['name'],
                    'code' => $city['code'],
                ]];
            }

            // 2️⃣ Совпадение по названию (частичное)
            if (str_contains($name, $query)) {
                $results[] = [
                    'name' => $city['name'],
                    'code' => $city['code'],
                ];
            }
        }

        // максимум 6 вариантов (для inline-кнопок)
        return array_slice($results, 0, 6);
    }

}
