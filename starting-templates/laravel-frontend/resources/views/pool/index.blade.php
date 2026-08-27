<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <meta name="theme-color" content="#1B1410">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Second Game Pool</title>
  <link rel="manifest" href="{{ asset('manifest.json') }}">
  <link rel="apple-touch-icon" href="{{ asset('icons/pool-192.png') }}">
  <link rel="icon" href="{{ asset('icons/pool-192.png') }}">
  <link rel="stylesheet" href="{{ asset('css/pool.css') }}">
</head>
<body>
  <div id="root"></div>

  {{--
    POOL_SEASON_ID tells the app which season to pull from the API the very
    first time it loads with a connection. Swap in the active season's id
    from a controller, e.g. return view('pool.index', ['seasonId' => $season->id]);
  --}}
  <script>
    window.POOL_SEASON_ID = @json($seasonId ?? null);
  </script>

  <script src="{{ asset('js/idb.js') }}"></script>
  <script src="{{ asset('js/api-sync.js') }}"></script>
  <script src="{{ asset('js/pool-app.js') }}"></script>
</body>
</html>
