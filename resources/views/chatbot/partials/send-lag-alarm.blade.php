{{-- Ang send lag alarm ng Forbeast (ForbeastSendHealth): pula habang aktibo. Walang lipat sa Botcake sa alarm na ito. --}}
@if($sendLagAlarm)
    <p class="mb-4 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" data-alarm="send_lag">
        <strong>Forbeast is slow to send replies</strong> (since {{ $sendLagAlarm['since'] }}): {{ $sendLagAlarm['error'] }}. Customers on Forbeast pages wait longer than the reply times show. The pages stay on Forbeast (no automatic switch).
    </p>
@endif
