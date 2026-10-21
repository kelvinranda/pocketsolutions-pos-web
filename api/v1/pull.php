<?php
/** GET — the full catalogue for this till's company + branch (products, groups, tax, units, payment methods). */
require __DIR__ . '/_bootstrap.php';
$till = apiTill($conn);
$data = psDtCatalogue($conn, $till);
$data['catalogue_hash'] = hash('sha256', json_encode([$data['products'], $data['button_groups'], $data['tax_rates'], $data['units'], $data['tax_enforcement'], $data['cash_out_approval_limit'] ?? null, $data['till_add_customers'] ?? null]));
apiRespond(['success' => true] + $data);
