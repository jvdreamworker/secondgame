<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#1B1410">
  <title>Sign in — Second Game Pool</title>
  <link rel="icon" href="{{ asset('icons/pool-192.png') }}">
  <link rel="stylesheet" href="{{ asset('css/pool.css') }}">
  <style>
    .login-wrap { max-width: 380px; margin: 0 auto; padding: 64px 16px; }
    .login-title { font-weight: 900; font-size: 20px; color: var(--cream); margin: 0 0 4px; }
    .login-sub { color: var(--cream-dim); font-size: 13px; margin: 0 0 24px; }
    .login-err { background: var(--red-dim); color: var(--cream); border-radius: 10px; padding: 10px 12px; font-size: 13px; margin-bottom: 16px; }
    .checkbox-row { display: flex; align-items: center; gap: 8px; color: var(--cream-dim); font-size: 13px; margin: 12px 0; }
  </style>
</head>
<body>
  <div class="login-wrap">
    <h1 class="login-title">Second Game Pool</h1>
    <p class="login-sub">Operator sign in</p>

    @if ($errors->any())
      <div class="login-err">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}">
      @csrf
      <label class="label-xs" for="email">Email</label>
      <input class="input" id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">

      <label class="label-xs" for="password">Password</label>
      <input class="input" id="password" type="password" name="password" required autocomplete="current-password">

      <label class="checkbox-row">
        <input type="checkbox" name="remember" value="1"> Keep me signed in
      </label>

      <button class="btn btn-green w-full mt-3" type="submit">Sign in</button>
    </form>
  </div>
</body>
</html>
