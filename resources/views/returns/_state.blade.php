{{-- What a return's state means to the buyer, in one sentence with the next step. Variables: $return, $respondDays. --}}
@php use App\Enums\ReturnStatus; @endphp
@switch($return->status)
    @case(ReturnStatus::Requested)
        <x-ds.alert tone="cau" title="Waiting for the seller">The seller has until {{ $return->respond_by?->format('j M Y') }} ({{ $respondDays }} days from your request) to answer. If they do not, the return goes to the Emall team's queue automatically.</x-ds.alert>
        @break
    @case(ReturnStatus::Approved)
        <x-ds.alert tone="pos" title="Approved: send the item back">{{ $return->seller_response ? 'The seller wrote: '.$return->seller_response : 'The seller approved your return.' }} Send the item back as the seller explains. Once the seller confirms it arrived, your refund is recorded.</x-ds.alert>
        @break
    @case(ReturnStatus::Declined)
        <x-ds.alert tone="crit" title="The seller declined this return">Their reason: {{ $return->seller_response ?: 'none was recorded' }}. If you disagree, you can send it to the Emall team, who read both sides and decide.</x-ds.alert>
        @break
    @case(ReturnStatus::Received)
        <x-ds.alert tone="info" title="The seller has the item back">Your refund is being recorded now.</x-ds.alert>
        @break
    @case(ReturnStatus::Refunded)
        <x-ds.alert tone="pos" title="Refunded">The refund for this item has been recorded{{ $return->refunded_at ? ' on '.$return->refunded_at->format('j M Y') : '' }}. Its progress with Paystack is shown below. Delivery fees are not refunded by a return.</x-ds.alert>
        @break
    @case(ReturnStatus::Escalated)
        <x-ds.alert tone="info" title="With the Emall team">{{ $return->escalation_reason === 'seller_no_response' ? 'The seller did not answer in time, so this came to us automatically.' : 'You sent this to us after the seller declined.' }} We read both sides and decide. You will get an e-mail with the outcome.</x-ds.alert>
        @break
    @case(ReturnStatus::Closed)
        <x-ds.alert tone="neutral" title="Closed">{{ $return->resolution_note ? 'The Emall team wrote: '.$return->resolution_note : 'This return is closed.' }}</x-ds.alert>
        @break
@endswitch
