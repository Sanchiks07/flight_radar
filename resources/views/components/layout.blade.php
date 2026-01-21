<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? "Flight Radar" }}</title>
    <link rel="stylesheet" href="{{ asset('style.css') }}">
    <script src="//cdn.jsdelivr.net/npm/globe.gl"></script>
</head>
<body>
    {{ $slot }}
</body>
</html>