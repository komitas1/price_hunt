<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PriceAlert;
use Illuminate\Support\Facades\Http;

class CheckPriceAlerts extends Command
{
    protected $signature = 'price:check';
    protected $description = 'Проверка снижения цен для всех активных PriceAlert';

    public function handle()
    {
        $alerts = PriceAlert::where('is_active', true)->get();

        foreach ($alerts as $alert) {
            $tickets = $this->searchTickets(
                $alert->origin,
                $alert->destination,
                $alert->depart_date,
                $alert->passengers
            );

            if (!$tickets || count($tickets) == 0) continue;

            // минимальная цена
            $minPrice = collect($tickets)->min('price');

            if ($minPrice <= $alert->target_price) {
                $this->sendTelegram($alert->tg_user_id, "✈ Внимание! Цена на рейс {$alert->origin} → {$alert->destination} {$alert->depart_date} снизилась до {$minPrice} ₽. Ссылка для покупки: ".$tickets[0]['link']);

                // Обновляем текущую цену
                $alert->current_price = $minPrice;
                $alert->save();
            }
        }

        $this->info('Проверка цен завершена.');
    }

    private function searchTickets($from, $to, $date, $passengers)
    {
        // start search
        $response = Http::withHeaders([
            'x-affiliate-user-id' => env('TRAVELPAYOUTS_API_TOKEN'),
            'x-real-host' => env('TRAVELPAYOUTS_REAL_HOST'),
            'Content-Type' => 'application/json'
        ])->post('https://tickets-api.travelpayouts.com/search/affiliate/start', [
            'origin' => $from,
            'destination' => $to,
            'depart_date' => $date,
            'adults' => $passengers
        ]);

        if (!$response->ok()) return null;
        $searchId = $response->json('search_id');

        sleep(3); // ждём генерации результатов

        $results = Http::withHeaders([
            'x-affiliate-user-id' => env('TRAVELPAYOUTS_API_TOKEN'),
            'x-real-host' => env('TRAVELPAYOUTS_REAL_HOST')
        ])->get("https://tickets-api.travelpayouts.com/search/affiliate/results/$searchId");

        return $results->json('tickets');
    }

    private function sendTelegram($chatId, $text)
    {
        Http::post("https://api.telegram.org/bot".env('TELEGRAM_BOT_TOKEN')."/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text
        ]);
    }
}
