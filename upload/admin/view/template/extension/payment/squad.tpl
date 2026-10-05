<?php echo $header; ?><?php echo $column_left; ?>
<?php
$escape = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$formatAmount = function ($minor) {
    $digits = str_pad((string)$minor, 3, '0', STR_PAD_LEFT);
    return substr($digits, 0, -2) . '.' . substr($digits, -2);
};
?>
<div id="content"><div class="page-header"><div class="container-fluid">
<div class="pull-right"><button type="submit" form="form-squad" class="btn btn-primary"><i class="fa fa-save"></i> <?php echo $escape($button_save); ?></button> <a class="btn btn-default" href="<?php echo $escape($cancel); ?>"><?php echo $escape($button_cancel); ?></a></div>
<h1><?php echo $escape($heading_title); ?></h1></div></div>
<div class="container-fluid">
<?php if ($error_warning) { ?><div class="alert alert-danger"><?php echo $escape($error_warning); ?></div><?php } ?>
<?php if ($success) { ?><div class="alert alert-success"><?php echo $escape($success); ?></div><?php } ?>
<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title"><?php echo $escape($text_edit); ?></h3></div><div class="panel-body">
<p style="margin-bottom:20px"><img src="<?php echo $escape($logo); ?>" alt="Squad by HabariPay" width="153" height="54" style="max-width:100%;height:auto"></p>
<form action="<?php echo $escape($action); ?>" method="post" id="form-squad" class="form-horizontal" autocomplete="off">
<div class="form-group"><label class="col-sm-3 control-label" for="squad-status"><?php echo $escape($entry_status); ?></label><div class="col-sm-9"><select id="squad-status" name="squad_status" class="form-control"><option value="0" <?php if (!$squad_status) echo 'selected'; ?>><?php echo $escape($text_disabled); ?></option><option value="1" <?php if ($squad_status) echo 'selected'; ?>><?php echo $escape($text_enabled); ?></option></select></div></div>
<div class="form-group"><label class="col-sm-3 control-label" for="squad-environment"><?php echo $escape($entry_environment); ?></label><div class="col-sm-9"><select id="squad-environment" name="squad_environment" class="form-control"><option value="sandbox" <?php if ($squad_environment === 'sandbox') echo 'selected'; ?>><?php echo $escape($text_test); ?></option><option value="live" <?php if ($squad_environment === 'live') echo 'selected'; ?>><?php echo $escape($text_live); ?></option></select></div></div>
<?php foreach (['sandbox','live'] as $environment) { ?>
<div class="squad-key-group" data-environment="<?php echo $environment; ?>" <?php if ($squad_environment !== $environment) echo 'style="display:none"'; ?>>
<?php foreach (['secret','public'] as $kind) { $label = ${'entry_' . $environment . '_' . $kind}; ?>
<div class="form-group"><label class="col-sm-3 control-label" for="squad-<?php echo $environment . '-' . $kind; ?>-key"><?php echo $escape($label); ?></label><div class="col-sm-9"><input type="password" id="squad-<?php echo $environment . '-' . $kind; ?>-key" name="squad_<?php echo $environment . '_' . $kind; ?>_input" value="" class="form-control" autocomplete="new-password" maxlength="512" placeholder="<?php echo $escape(${$environment . '_' . $kind . '_configured'} ? $text_key_saved : $text_key_empty); ?>"></div></div>
<?php } ?></div><?php } ?>
<script>
(function () {
    var mode = $('#squad-environment');
    function showSelectedKeys() {
        $('.squad-key-group').each(function () { $(this).toggle($(this).attr('data-environment') === mode.val()); });
    }
    mode.on('change.squad', showSelectedKeys);
    showSelectedKeys();
})();
</script>
<div class="form-group"><label class="col-sm-3 control-label" for="squad-merchant"><?php echo $escape($entry_merchant_id); ?></label><div class="col-sm-9"><input type="text" id="squad-merchant" name="squad_merchant_id" value="<?php echo $escape($squad_merchant_id); ?>" class="form-control" maxlength="64"></div></div>
<div class="form-group"><label class="col-sm-3 control-label"><?php echo $escape($entry_currencies); ?></label><div class="col-sm-9"><?php foreach (['NGN','USD'] as $currency) { ?><label class="checkbox-inline"><input type="checkbox" name="squad_currencies[]" value="<?php echo $currency; ?>" <?php if (in_array($currency, $squad_currencies, true)) echo 'checked'; ?>><?php echo $currency; ?></label><?php } ?><p class="help-block">Enable only currencies your Squad account supports. USD uses card checkout. The merchant absorbs gateway fees.</p></div></div>
<?php foreach (['paid'=>$entry_paid_status,'pending'=>$entry_pending_status] as $type=>$label) { $selected = ${'squad_' . $type . '_status_id'}; ?>
<div class="form-group"><label class="col-sm-3 control-label" for="squad-<?php echo $type; ?>-status"><?php echo $escape($label); ?></label><div class="col-sm-9"><select id="squad-<?php echo $type; ?>-status" name="squad_<?php echo $type; ?>_status_id" class="form-control"><?php foreach ($order_statuses as $status) { ?><option value="<?php echo (int)$status['order_status_id']; ?>" <?php if ((int)$selected === (int)$status['order_status_id']) echo 'selected'; ?>><?php echo $escape($status['name']); ?></option><?php } ?></select></div></div>
<?php } ?>
<div class="form-group"><label class="col-sm-3 control-label" for="squad-zone"><?php echo $escape($entry_geo_zone); ?></label><div class="col-sm-9"><select id="squad-zone" name="squad_geo_zone_id" class="form-control"><option value="0"><?php echo $escape($text_all_zones); ?></option><?php foreach ($geo_zones as $zone) { ?><option value="<?php echo (int)$zone['geo_zone_id']; ?>" <?php if ((int)$squad_geo_zone_id === (int)$zone['geo_zone_id']) echo 'selected'; ?>><?php echo $escape($zone['name']); ?></option><?php } ?></select></div></div>
<?php foreach (['total'=>$entry_total,'sort_order'=>$entry_sort_order] as $name=>$label) { ?>
<div class="form-group"><label class="col-sm-3 control-label" for="squad-<?php echo $name; ?>"><?php echo $escape($label); ?></label><div class="col-sm-9"><input type="text" id="squad-<?php echo $name; ?>" class="form-control" name="squad_<?php echo $name; ?>" value="<?php echo $escape(${'squad_' . $name}); ?>"></div></div>
<?php } ?></form>
<hr><p><strong>Webhook URL</strong><br><code><?php echo $escape($webhook); ?></code></p><p><strong>Callback URL</strong><br><code><?php echo $escape($callback); ?></code></p><p>Live payments require HTTPS. Local sandbox webhook testing requires a public HTTPS tunnel.</p>
<p class="help-block"><?php echo $escape($text_storage); ?></p>
</div></div>
<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title"><?php echo $escape($text_attempts); ?></h3></div><div class="panel-body"><p><?php echo $escape($text_review_help); ?></p><div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Order</th><th>Reference</th><th>Amount</th><th>Mode</th><th>State</th><th>Last check</th><th>Action</th></tr></thead><tbody>
<?php foreach ($attempts as $attempt) { ?><tr><td><?php echo (int)$attempt['order_id']; ?></td><td><code><?php echo $escape($attempt['reference']); ?></code></td><td><?php echo $escape($formatAmount($attempt['amount']) . ' ' . $attempt['currency']); ?></td><td><?php echo $escape($attempt['environment']); ?></td><td><?php echo $escape($attempt['state']); ?><?php if ($attempt['last_error']) { ?><br><small><?php echo $escape($attempt['last_error']); ?></small><?php } ?></td><td><?php echo $escape($attempt['updated_at']); ?></td><td><form action="<?php echo $escape($recheck); ?>" method="post" target="_blank"><input type="hidden" name="reference" value="<?php echo $escape($attempt['reference']); ?>"><button class="btn btn-default btn-sm"><?php echo $escape($text_recheck); ?></button></form></td></tr><?php } ?>
</tbody></table></div></div></div>
<div class="panel panel-default"><div class="panel-body"><strong><?php echo $escape($text_author); ?></strong><br><a href="https://knackm.com" target="_blank" rel="noopener noreferrer">knackm.com</a> · <a href="https://github.com/afije/" target="_blank" rel="noopener noreferrer">github.com/afije</a><span class="pull-right">Squad by HabariPay · OpenCart 2.3 · v1.0.10</span></div></div>
</div></div><?php echo $footer; ?>
