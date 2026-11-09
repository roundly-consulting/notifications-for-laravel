<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }}</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f5f5f4; color: #1c1917; }
        main { max-width: 32rem; margin: 4rem auto; padding: 2rem; background: #fff; border-radius: .5rem; }
        h1 { font-size: 1.25rem; margin: 0 0 1rem; }
        p { line-height: 1.5; }
        button { font: inherit; padding: .6rem 1.2rem; border: 0; border-radius: .375rem; background: #1c1917; color: #fff; cursor: pointer; }
    </style>
</head>
<body>
<main>
    <h1>{{ $title }}</h1>
    <p>{{ $message }}</p>
@if ($button !== null)
    <form method="post">
        <input type="hidden" name="List-Unsubscribe" value="One-Click">
        <button type="submit">{{ $button }}</button>
    </form>
@endif
@if ($hint !== null)
    <p><small>{{ $hint }}</small></p>
@endif
</main>
</body>
</html>
