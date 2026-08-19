<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>GoedGepickt stock mutations rejected</title>
</head>
<body style="margin: 0; padding: 0; background: #f3f4f6; color: #111827; font-family: Arial, sans-serif;">
<div style="max-width: 1100px; margin: 0 auto; padding: 24px;">
    <div style="background: #111827; color: #ffffff; border-radius: 12px 12px 0 0; padding: 20px 24px;">
        <h1 style="margin: 0; font-size: 20px; line-height: 1.4;">
            GoedGepickt stock mutations rejected
        </h1>

        <p style="margin: 8px 0 0; color: #d1d5db; font-size: 14px;">
            Captura Connect detected rejected stock mutations during a supplier sync.
        </p>
    </div>

    <div style="background: #ffffff; border: 1px solid #e5e7eb; border-top: 0; border-radius: 0 0 12px 12px; padding: 24px;">
        <table style="width: 100%; margin-bottom: 24px; border-collapse: collapse;">
            <tr>
                <td style="padding: 8px 0; color: #6b7280; font-size: 13px; width: 160px;">
                    Supplier
                </td>
                <td style="padding: 8px 0; font-size: 14px; font-weight: 600;">
                    {{ $supplier }}
                </td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #6b7280; font-size: 13px;">
                    Failed mutations
                </td>
                <td style="padding: 8px 0; font-size: 14px; font-weight: 600;">
                    {{ count($failedItems) }}
                </td>
            </tr>
        </table>

        <table style="width: 100%; border-collapse: collapse; border: 1px solid #e5e7eb; font-size: 13px;">
            <thead>
            <tr style="background: #f9fafb;">
                <th style="padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: left;">EAN</th>
                <th style="padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: left;">SKU</th>
                <th style="padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: left;">Product</th>
                <th style="padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: left;">Supplier SKU</th>
                <th style="padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: right;">Delta</th>
                <th style="padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: right;">Before</th>
                <th style="padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: right;">After</th>
                <th style="padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: right;">Status</th>
            </tr>
            </thead>

            <tbody>
            @foreach ($failedItems as $item)
                <tr>
                    <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">
                        {{ $item['ean'] ?? '-' }}
                    </td>
                    <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">
                        {{ $item['sku'] ?? '-' }}
                    </td>
                    <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">
                        @if (! empty($item['goedgepickt_url']))
                            <a
                                href="{{ $item['goedgepickt_url'] }}"
                                style="display: inline-block; color: #2563eb; font-weight: 600; text-decoration: underline;"
                            >
                                Open product
                            </a>
                        @else
                            -
                        @endif
                    </td>
                    <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">
                        {{ $item['supplier_sku'] ?? '-' }}
                    </td>
                    <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: right;">
                        {{ $item['delta'] ?? '-' }}
                    </td>
                    <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: right;">
                        {{ $item['stock_before'] ?? '-' }}
                    </td>
                    <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: right;">
                        {{ $item['stock_after'] ?? '-' }}
                    </td>
                    <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: right;">
                        {{ $item['status'] ?? '-' }}
                    </td>
                </tr>

                <tr>
                    <td colspan="8" style="padding: 10px; border-bottom: 1px solid #e5e7eb; background: #f9fafb;">
                        <div style="margin-bottom: 6px; color: #6b7280; font-size: 12px; font-weight: 600; text-transform: uppercase;">
                            GoedGepickt response
                        </div>

                        <pre style="margin: 0; white-space: pre-wrap; word-break: break-word; background: #111827; color: #f9fafb; border-radius: 8px; padding: 12px; font-size: 12px; line-height: 1.5;">{{ json_encode($item['body'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <p style="margin: 24px 0 0; color: #6b7280; font-size: 12px;">
            This email was sent automatically by Captura Connect.
        </p>
    </div>
</div>
</body>
</html>
