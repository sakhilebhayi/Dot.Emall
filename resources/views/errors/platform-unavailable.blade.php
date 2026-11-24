{{--
  R-61: shown with a 503 when InfoDot has switched Dot.Emall off (maintenance or unavailable). Same plain, standalone frame as errors/503: it reads no store, order or
  account, and nothing already recorded is changed. $message is InfoDot's own wording (escaped here).
--}}
<x-error-layout :code="503" heading="Dot.Emall is not available right now" :message="($message ?? 'Dot.Emall is temporarily unavailable.').' Your orders and your store are kept exactly as they are.'" />
