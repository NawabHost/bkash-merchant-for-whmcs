<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function bkashlegacy_MetaData()
{
    return [
        'DisplayName'                => 'bKash Merchant (Legacy)',
        'APIVersion'                 => '1.1',
        'DisableLocalCredtCardInput' => true,
        'TokenisedStorage'           => false,
    ];
}

function bkashlegacy_config()
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'bKash Merchant (Legacy)',
        ],
        'msisdn'       => [
            'FriendlyName' => 'Merchant No',
            'Type'         => 'text',
            'Size'         => '25',
            'Default'      => '',
            'Description'  => 'Enter your merchant number here',
        ],
        'user'         => [
            'FriendlyName' => 'API Username',
            'Type'         => 'text',
            'Size'         => '25',
            'Default'      => '',
            'Description'  => 'Enter your merchant api user here',
        ],
        'pass'         => [
            'FriendlyName' => 'API Password',
            'Type'         => 'password',
            'Size'         => '25',
            'Default'      => '',
            'Description'  => 'Enter merchant api pass here',
        ],
        'fee'          => [
            'FriendlyName' => 'Gateway Fee',
            'Type'         => 'text',
            'Size'         => '25',
            'Default'      => 1.85,
            'Description'  => 'Enter the gateway fee in percentages.',
        ],
        'verifyType'   => [
            'FriendlyName' => 'Verify Method',
            'Type'         => 'dropdown',
            'Default'      => 'trxid',
            'Options'      => [
                'sendmsg' => 'Transaction',
                'refmsg'  => 'Reference',
            ],
            'Description'  => 'Select which method you want to use for transaction verification.',
        ],
        'counter'      => [
            'FriendlyName' => 'Counter',
            'Type'         => 'text',
            'Size'         => '25',
            'Default'      => 0,
            'Description'  => 'Enter counter number if want.',
        ],
    ];
}

function bkashlegacy_getTotalPayable($params)
{
    $fee = empty($params['fee']) ? 0 : (($params['fee'] / 100) * $params['amount']);

    return ceil($params['amount'] + $fee);
}

function bkashlegacy_link($params)
{
    $totalDue = bkashlegacy_getTotalPayable($params);
    $action   = $params['systemurl'] . 'modules/gateways/callback/' . $params['paymentmethod'] . '.php';
    $scripts  = bkashlegacy_scriptsHandle($params);

    $fields = <<<HTML
    <input type="text" name="trxId" id="bkashlegacy-trxid" placeholder="ABC134CDEF" style="padding: 0px 6px; border: 1px solid #d9116b; border-radius: 4px;" required>
HTML;

    if ($params['verifyType'] === 'refmsg') {
        $fields = '<p>Already paid? Please click "Verify" button.</p>';
    }

    return <<<HTML
<div style="text-align: left;">
    <ol>
        <li>Dial <span style="background-color: #d9116b; padding: 2px 4px; border-radius: 4px; color: #fff;">*247#</span> to open bKash menu.</li>
        <li>Enter <span style="background-color: #d9116b; padding: 2px 4px; border-radius: 4px; color: #fff;">4</span> for <span style="background-color: #d9116b; padding: 2px 4px; border-radius: 4px; color: #fff;">Payment</span></li>
        <li>Enter Number: <span style="background-color: #d9116b; padding: 2px 4px; border-radius: 4px; color: #fff;">{$params['msisdn']}</span></li></li>
        <li>Enter Due Amount <span style="background-color: #d9116b; padding: 2px 4px; border-radius: 4px; color: #fff;">{$totalDue}</span> Taka</li>
        <li>Enter <span style="background-color: #d9116b; padding: 2px 4px; border-radius: 4px; color: #fff;">{$params['invoiceid']}</span> as Reference</li>
        <li>Enter <span style="background-color: #d9116b; padding: 2px 4px; border-radius: 4px; color: #fff;">{$params['counter']}</span> as Counter Number</li>
        <li>Enter PIN and Confirm</li>
    </ol>
    <form id="bkashlegacy-form" style="text-align: center;" action="$action" method="POST">
        <input type="hidden" name="id" value="{$params['invoiceid']}">
        {$fields}
        <button type="submit" id="bkashlegacy-btn" style="border: 1px solid #d9116b;padding: 0px 10px;background: #d9116b;color: #fff;border-radius: 4px;">Verify</button>
    </form>
    <div id="bkashlegacy-response" style="display: none; text-align: center; font-size: 13px; padding: 5px 10px; margin-top: 10px; background: #f9ecec; border: 1px solid #ff1818; border-radius: 4px;"></div>
</div>
{$scripts}
HTML;
}

function bkashlegacy_scriptsHandle($params)
{
    $apiUrl = $params['systemurl'] . 'modules/gateways/callback/' . $params['paymentmethod'] . '.php';
    $markup = <<<HTML
<script>
    window.addEventListener('load', function() {
        var bkashBtn = $('#bkashlegacy-btn');
        var bKashResponse = $('#bkashlegacy-response');

        $('#bkashlegacy-form').on('submit', function(e) {
            e.preventDefault();

            bkashBtn.attr('disabled', 'disabled');

            $.ajax({
                method: "POST",
                url: "{$apiUrl}",
                data: $('#bkashlegacy-form').serialize()
            }).done(function(response) {
                if (response.status === 'success') {
                    window.location = "{$params['returnurl']}" + "&paymentsuccess=true";
                } else {
                   bKashResponse.show();
                   bKashResponse.text(response.message);
                }

            }).fail(function() {
                bKashResponse.show();
                bKashResponse.text('Something is wrong! Please contact support.');
              }).always(function () {
                bkashBtn.removeAttr('disabled');
            });
        })
    });
</script>
HTML;

    return $markup;
}
