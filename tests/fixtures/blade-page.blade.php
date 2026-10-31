<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Blade fixture</title>
</head>
<body>
<main>
    <h1>Blade fixture</h1>

    <img src="/missing-1.png"> {{-- GT:B1 image-alt | plain Blade page --}}

    <form>
        <input type="text" name="plain"> {{-- GT:B2 label | plain Blade page --}}
    </form>

    <a href="/somewhere"></a> {{-- GT:B3 link-name | plain Blade page --}}

    <p style="color:#c8c8c8;background:#ffffff">Low contrast text</p> {{-- GT:B4 color-contrast | inline style --}}

    <x-icon-button />
    <x-icon-button />

    <x-avatar src="/user.png" />

    @foreach ([1, 2, 3] as $i)
        @include('fixture.partials.card', ['i' => $i])
    @endforeach
</main>
</body>
</html>
