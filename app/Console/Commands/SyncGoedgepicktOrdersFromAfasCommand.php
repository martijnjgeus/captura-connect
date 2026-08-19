<?php

namespace App\Console\Commands;

use App\Actions\Orders\SyncGoedgepicktOrdersFromAfas;
use App\Services\Afas\AfasDeliveryNoteClient;
use Illuminate\Console\Command;

class SyncGoedgepicktOrdersFromAfasCommand extends Command
{
    protected $signature = 'orders:sync-goedgepickt-from-afas
        {--dry-run : Do not post orders to GoedGepickt or update AFAS}
        {--inspect : Only inspect AFAS delivery note fields and write a sample JSON file}
        {--take=5 : Number of AFAS lines to include in inspect output}';

    protected $description = 'Sync AFAS delivery notes to GoedGepickt orders.';

    public function handle(
        SyncGoedgepicktOrdersFromAfas $sync,
        AfasDeliveryNoteClient $afasDeliveryNoteClient,
    ): int {
        if ((bool) $this->option('inspect')) {
            return $this->inspectAfasDeliveryNoteFields($afasDeliveryNoteClient);
        }

        $result = $sync->handle(
            dryRun: (bool) $this->option('dry-run'),
        );

        $this->info('AFAS to GoedGepickt order sync finished.');
        $this->line('Dry run: '.($result['dry_run'] ? 'yes' : 'no'));
        $this->line('AFAS lines read: '.$result['afas_lines_read']);
        $this->line('AFAS delivery notes grouped: '.$result['afas_delivery_notes_grouped']);
        $this->line('Skipped lines: '.$result['skipped_lines']);
        $this->line('Skipped not postable orders: '.$result['skipped_not_postable_orders']);
        $this->line('Already marked processed locally: '.$result['already_marked_processed_locally']);
        $this->line('Already posted to GoedGepickt locally: '.$result['already_posted_to_goedgepickt_locally']);
        $this->line('Orders built: '.$result['orders_built']);
        $this->line('GoedGepickt attempted: '.$result['goedgepickt_attempted']);
        $this->line('GoedGepickt succeeded: '.$result['goedgepickt_succeeded']);
        $this->line('GoedGepickt failed: '.$result['goedgepickt_failed']);
        $this->line('AFAS mark processed queued: '.$result['afas_mark_processed_queued']);
        $this->line('AFAS mark processed attempted: '.$result['afas_mark_processed_attempted']);
        $this->line('AFAS mark processed succeeded: '.$result['afas_mark_processed_succeeded']);
        $this->line('AFAS mark processed failed: '.$result['afas_mark_processed_failed']);
        $this->line('AFAS mark processed skipped: '.$result['afas_mark_processed_skipped']);

        return self::SUCCESS;
    }

    private function inspectAfasDeliveryNoteFields(AfasDeliveryNoteClient $afasDeliveryNoteClient): int
    {
        $take = (int) $this->option('take');

        if ($take < 1) {
            $take = 5;
        }

        $lines = $afasDeliveryNoteClient->getUnprocessedDeliveryNoteLines();
        $sampleLines = array_slice($lines, 0, $take);

        $fields = [];

        foreach ($sampleLines as $line) {
            if (! is_array($line)) {
                continue;
            }

            foreach ($line as $field => $value) {
                if (! array_key_exists($field, $fields)) {
                    $fields[$field] = [
                        'field' => $field,
                        'example_values' => [],
                    ];
                }

                if ($value === null) {
                    continue;
                }

                if (! is_scalar($value)) {
                    continue;
                }

                $value = trim((string) $value);

                if ($value === '') {
                    continue;
                }

                if (! in_array($value, $fields[$field]['example_values'], true)) {
                    $fields[$field]['example_values'][] = $value;
                }

                $fields[$field]['example_values'] = array_slice(
                    $fields[$field]['example_values'],
                    0,
                    5
                );
            }
        }

        ksort($fields);

        $output = [
            'afas_lines_read' => count($lines),
            'sample_lines_count' => count($sampleLines),
            'fields' => array_values($fields),
            'sample_lines' => $sampleLines,
        ];

        $path = storage_path('app/afas-delivery-note-field-inspection.json');

        file_put_contents(
            $path,
            json_encode(
                $output,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_PARTIAL_OUTPUT_ON_ERROR
            )
        );

        $this->info('AFAS delivery note field inspection finished.');
        $this->line('AFAS lines read: '.count($lines));
        $this->line('Sample lines: '.count($sampleLines));
        $this->line('Fields found: '.count($fields));
        $this->line('Written to: '.$path);

        $this->newLine();

        foreach ($fields as $field) {
            $examples = implode(' | ', $field['example_values']);

            $this->line($field['field'].($examples !== '' ? ' = '.$examples : ''));
        }

        return self::SUCCESS;
    }
}
