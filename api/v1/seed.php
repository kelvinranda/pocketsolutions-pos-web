<?php
/** GET — full standalone setup for a desktop POS: company, users (bcrypt), customers + accounts, stock, catalogue. */
require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/stock_movement.php';
$till = apiTill($conn);
$data = psDtSeed($conn, $till);
$data['catalogue_hash'] = hash('sha256', json_encode([$data['products'], $data['button_groups'], $data['tax_rates'], $data['units'], $data['tax_enforcement'], $data['cash_out_approval_limit'] ?? null, $data['till_add_customers'] ?? null]));
psSecurityAudit($conn, 'DESKTOP_SEED', ['actor' => 'desktop', 'source' => 'desktop', 'summary' => 'Desktop till ' . $till['till_code'] . ' downloaded company data (' . count($data['users']) . ' users, ' . count($data['customers']) . ' customers)', 'target_type' => 'desktop_till', 'target_id' => (string)$till['id']]);
apiRespond(['success' => true] + $data);
