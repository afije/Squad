<?php if ($sandbox) { ?><div class="alert alert-warning"><?php echo htmlspecialchars($text_testmode, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
<p><img src="<?php echo htmlspecialchars($logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Squad by HabariPay" width="153" height="54" style="max-width:100%;height:auto"></p>
<p><?php echo htmlspecialchars($text_redirect, ENT_QUOTES, 'UTF-8'); ?></p>
<div id="squad-error" class="alert alert-danger" style="display:none" role="alert"></div>
<div class="buttons"><div class="pull-right"><button type="button" id="button-confirm" class="btn btn-primary" data-loading-text="Please wait…"><?php echo htmlspecialchars($button_confirm, ENT_QUOTES, 'UTF-8'); ?></button></div></div>
<script>
(function () {
    var busy = false;
    $('#button-confirm').off('click.squad').on('click.squad', function () {
        if (busy) return;
        busy = true;
        var button = $(this);
        button.prop('disabled', true);
        $('#squad-error').hide();
        $.ajax({
            url: <?php echo json_encode(html_entity_decode($start, ENT_QUOTES, 'UTF-8'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            type: 'post', dataType: 'json',
            data: {csrf: <?php echo json_encode($csrf); ?>},
            success: function (result) {
                if (result.redirect) { window.location.assign(result.redirect); return; }
                showError(result.error);
            },
            error: function (xhr) { showError(xhr.responseJSON && xhr.responseJSON.error); }
        });
        function showError(message) {
            $('#squad-error').text(message || <?php echo json_encode($text_error, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>).show();
            busy = false; button.prop('disabled', false);
        }
    });
})();
</script>
