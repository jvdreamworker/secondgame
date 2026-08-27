<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#1B1410">
  <title>Change password — Second Game Pool</title>
  <link rel="icon" href="{{ asset('icons/pool-192.png') }}">
  <link rel="stylesheet" href="{{ asset('css/pool.css') }}">
  <style>
    .pw-wrap { max-width: 380px; margin: 0 auto; padding: 48px 16px; }
    .pw-title { font-weight: 900; font-size: 20px; color: var(--cream); margin: 0 0 24px; }
    .pw-err { background: var(--red-dim); color: var(--cream); border-radius: 10px; padding: 10px 12px; font-size: 13px; margin-bottom: 16px; }
    .pw-back { display: inline-block; color: var(--cream-dim); font-size: 13px; margin-top: 16px; }
  </style>
</head>
<body>
  <div class="pw-wrap">
    <h1 class="pw-title">Change password</h1>

    @if ($errors->any())
      <div class="pw-err">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.update') }}">
      @csrf
      @method('PUT')
      <label class="label-xs" for="current_password">Current password</label>
      <input class="input" id="current_password" type="password" name="current_password" required autocomplete="current-password">

      <label class="label-xs" for="password">New password</label>
      <input class="input" id="password" type="password" name="password" required autocomplete="new-password">

      <label class="label-xs" for="password_confirmation">Confirm new password</label>
      <input class="input" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">

      <button class="btn btn-green w-full mt-3" type="submit">Update password</button>
    </form>

    <a class="pw-back" href="/pool">&larr; Back to the pool</a>
  </div>
</body>
</html>
