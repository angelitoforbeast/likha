{{--
  TV mode: isang screen na ipinapakita sa TV ng opisina. Walang top bar, nav o banner ng Chatbot (ang page ang may sariling
  maliit na link palabas), walang Tailwind: ang page ang may buong itsura. Ang bawat page: @section('title'),
  @section('content'), at @push('styles') / @push('scripts').
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Chatbot · @yield('title', 'Live')</title>
    @stack('styles')
</head>
<body data-tv>
@yield('content')
@stack('scripts')
</body>
</html>
