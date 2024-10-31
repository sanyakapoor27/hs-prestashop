{if $status == 'success'}
    <div class="alert alert-success">
        <p class="h3">
            {l s='Thank you for shopping with %s!' sprintf=[$shop_name] mod='hyperswitch'}
        </p>
        <p>
            {l s='Your order has been successfully processed.' mod='hyperswitch'}
        </p>
        <ul>
            <li>
                {l s='Order reference:' mod='hyperswitch'} 
                <span class="font-weight-bold">{$reference}</span>
            </li>
            <li>
                {l s='Total amount:' mod='hyperswitch'} 
                <span class="font-weight-bold">{displayPrice price=$order->total_paid}</span>
            </li>
            <li>
                {l s='An email has been sent to your inbox containing the details of your order.' mod='hyperswitch'}
            </li>
        </ul>
        <p>
            {l s='For any questions or concerns, please contact our' mod='hyperswitch'} 
            <a href="{$contact_url}">{l s='customer support' mod='hyperswitch'}</a>.
        </p>
    </div>
{else}
    <div class="alert alert-danger">
        <p class="h3">
            {l s='Unfortunately, an error occurred during the payment process.' mod='hyperswitch'}
        </p>
        <p>
            {l s='Please try the following:' mod='hyperswitch'}
        </p>
        <ul>
            <li>{l s='Check your email for any payment confirmation' mod='hyperswitch'}</li>
            <li>{l s='Review your order history in your account to check the order status' mod='hyperswitch'}</li>
            <li>
                {l s='Contact our' mod='hyperswitch'} 
                <a href="{$contact_url}">{l s='customer support' mod='hyperswitch'}</a> 
                {l s='if you need assistance' mod='hyperswitch'}
            </li>
        </ul>
        <p>
            {l s='Please note: Your order will not be shipped until we receive payment confirmation.' mod='hyperswitch'}
        </p>
    </div>
{/if}