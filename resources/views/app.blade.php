<!DOCTYPE html>
{{-- The inline background matches --nx-paper (dark) so the window never flashes
     white in the moment before the stylesheet lands. --}}
<html lang="en" class="dark" style="background-color: #14110e">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>Nexus</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="antialiased">
    @inertia
</body>
</html>
