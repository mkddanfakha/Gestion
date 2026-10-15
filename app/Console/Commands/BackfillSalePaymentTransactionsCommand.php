<?php

namespace App\Console\Commands;

use App\Enums\PayableType;
use App\Enums\PaymentTransactionOrigin;
use App\Enums\PaymentTransactionStatus;
use App\Models\PaymentTransaction;
use App\Models\Sale;
use App\Models\User;
use App\Services\SalePaymentTransactionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillSalePaymentTransactionsCommand extends Command
{
    protected $signature = 'payments:backfill-sale-down-payments
                            {--dry-run : Analyser sans écrire en base}
                            {--apply : Appliquer la reprise (obligatoire pour toute écriture)}';

    protected $description = 'Reprise idempotente des montants déjà payés (sales.down_payment_amount) vers payment_transactions';

    public function handle(SalePaymentTransactionService $paymentService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if (! $dryRun && ! $apply) {
            $this->warn('Aucune option explicite : exécution en mode --dry-run.');

            $dryRun = true;
        }

        if ($apply && $dryRun) {
            $this->error('Utilisez soit --dry-run soit --apply, pas les deux.');

            return self::FAILURE;
        }

        $systemUser = User::query()->orderBy('id')->first();

        if ($systemUser === null) {
            $this->error('Aucun utilisateur en base : reprise impossible.');

            return self::FAILURE;
        }

        $stats = [
            'analyzed' => 0,
            'to_create' => 0,
            'skipped_already_backfilled' => 0,
            'skipped_no_payment' => 0,
            'anomalies' => 0,
            'created' => 0,
        ];

        $anomalyLines = [];

        Sale::query()
            ->orderBy('id')
            ->chunkById(100, function ($sales) use (
                $paymentService,
                $systemUser,
                $dryRun,
                $apply,
                &$stats,
                &$anomalyLines,
            ) {
                foreach ($sales as $sale) {
                    $stats['analyzed']++;

                    $downPayment = round((float) $sale->down_payment_amount, 2);
                    $total = round((float) $sale->total_amount, 2);
                    $remaining = round((float) $sale->remaining_amount, 2);

                    if ($downPayment <= 0) {
                        $stats['skipped_no_payment']++;

                        continue;
                    }

                    $existingBackfill = PaymentTransaction::query()
                        ->where('payable_type', PayableType::Sale)
                        ->where('payable_id', $sale->id)
                        ->where('origin', PaymentTransactionOrigin::HistoricalBackfill)
                        ->where('status', PaymentTransactionStatus::Posted)
                        ->exists();

                    if ($existingBackfill) {
                        $stats['skipped_already_backfilled']++;

                        continue;
                    }

                    $postedSum = $paymentService->sumPostedAmount(PayableType::Sale, (int) $sale->id);

                    if ($postedSum > SalePaymentTransactionService::MONEY_EPSILON) {
                        $stats['skipped_already_backfilled']++;
                        $anomalyLines[] = sprintf(
                            'Vente #%d (%s) : down_payment=%.2f mais %.2f déjà en transactions posted sans ligne historical_backfill.',
                            $sale->id,
                            $sale->sale_number,
                            $downPayment,
                            $postedSum,
                        );
                        $stats['anomalies']++;

                        continue;
                    }

                    $anomaly = $this->detectAnomaly($sale, $downPayment, $total, $remaining);

                    if ($anomaly !== null) {
                        $anomalyLines[] = sprintf(
                            'Vente #%d (%s) : %s',
                            $sale->id,
                            $sale->sale_number,
                            $anomaly,
                        );
                        $stats['anomalies']++;

                        continue;
                    }

                    $stats['to_create']++;

                    if ($apply) {
                        $substitutionDate = $sale->sale_date ?? $sale->updated_at ?? $sale->created_at;
                        $method = $sale->payment_method;

                        if ($method === null || $method === '') {
                            $method = 'cash';
                        }

                        if (! in_array($method, PaymentTransaction::PAYMENT_METHODS, true)) {
                            $anomalyLines[] = sprintf(
                                'Vente #%d (%s) : mode de paiement « %s » invalide pour reprise.',
                                $sale->id,
                                $sale->sale_number,
                                $method,
                            );
                            $stats['anomalies']++;
                            $stats['to_create']--;

                            continue;
                        }

                        DB::transaction(function () use (
                            $paymentService,
                            $sale,
                            $systemUser,
                            $downPayment,
                            $substitutionDate,
                            $method,
                            &$stats,
                        ) {
                            $paymentService->recordHistoricalBackfillForSale(
                                $sale,
                                $systemUser,
                                $downPayment,
                                $method,
                                $substitutionDate,
                            );

                            $stats['created']++;
                        });
                    }
                }
            });

        $this->info('Résumé reprise règlements ventes');
        $this->table(
            ['Indicateur', 'Valeur'],
            collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all(),
        );

        if ($anomalyLines !== []) {
            $this->warn('Anomalies / cas non repris :');
            foreach ($anomalyLines as $line) {
                $this->line(' - '.$line);
            }
        }

        if ($dryRun) {
            $this->comment('Mode dry-run : aucune écriture effectuée.');
        }

        return self::SUCCESS;
    }

    private function detectAnomaly(Sale $sale, float $downPayment, float $total, float $remaining): ?string
    {
        if ($downPayment < 0) {
            return 'down_payment_amount négatif.';
        }

        if ($total <= 0) {
            return 'total_amount nul ou négatif avec acompte positif.';
        }

        if ($downPayment > $total + SalePaymentTransactionService::MONEY_EPSILON) {
            return sprintf('down_payment_amount (%.2f) supérieur au total (%.2f).', $downPayment, $total);
        }

        $expectedRemaining = round($total - $downPayment, 2);

        if (abs($remaining - $expectedRemaining) > SalePaymentTransactionService::MONEY_EPSILON) {
            return sprintf(
                'Incohérence agrégats : down_payment=%.2f, remaining=%.2f, total=%.2f.',
                $downPayment,
                $remaining,
                $total,
            );
        }

        return null;
    }
}
