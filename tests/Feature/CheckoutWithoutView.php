<?php

namespace Reach\ResrvPaymentPaypal\Tests\Feature;

use Reach\StatamicResrv\Livewire\Checkout;

/** Resrv's Checkout without its view, which needs a site's checkout form and entries to render. */
class CheckoutWithoutView extends Checkout
{
    public function render()
    {
        return '<div></div>';
    }
}
