(function() {
    function selectAccount(id) {
        rcmail.http_post('plugin.sizestation_oidc.select-account', {'_ident-id': id});
    }

    $(function() {
        var accounts = rcmail.env.sizestation_oidc_accounts || [];
        if (accounts.length < 2) {
            return;
        }
        var overlay = $('<div>', {
            id: 'sizestation-oidc-account-selection',
            role: 'dialog',
            'aria-modal': 'true',
            'aria-labelledby': 'sizestation-oidc-account-selection-title'
        }).css({
            position: 'fixed', inset: 0, zIndex: 10000, background: 'rgba(0,0,0,.55)',
            display: 'flex', alignItems: 'center', justifyContent: 'center'
        });
        var panel = $('<div>').css({
            background: 'var(--color-main-background, #fff)', color: 'var(--color-main-text, #222)',
            borderRadius: '.5rem', padding: '1.5rem', width: 'min(28rem, 90vw)',
            boxShadow: '0 .5rem 2rem rgba(0,0,0,.3)'
        });
        panel.append($('<h2>', {
            id: 'sizestation-oidc-account-selection-title',
            text: rcmail.env.sizestation_oidc_account_prompt || 'Choose a mailbox'
        }));
        $.each(accounts, function(_, account) {
            $('<button>', {type: 'button', text: account.label})
                .addClass('button mainaction')
                .css({display: 'block', width: '100%', marginTop: '.75rem'})
                .on('click', function() {
                    $(this).prop('disabled', true);
                    selectAccount(account.id);
                })
                .appendTo(panel);
        });
        overlay.append(panel).appendTo(document.body);
        panel.find('button:first').trigger('focus');
    });
})();
