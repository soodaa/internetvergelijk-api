<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Er is iets misgegaan')</title>
    <style>
        :root {
            color-scheme: light dark;
        }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f4f6fb;
            color: #0f1b2d;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 2rem;
        }
        .card {
            background: #fff;
            padding: 2.5rem 3rem;
            border-radius: 18px;
            box-shadow: 0 20px 60px rgba(17, 34, 68, 0.12);
            max-width: 560px;
            width: 100%;
            text-align: center;
        }
        h1 {
            font-size: clamp(2rem, 4vw, 2.8rem);
            margin: 0;
            color: #0f1b2d;
        }
        p {
            font-size: 1.05rem;
            line-height: 1.6;
            margin: 1rem 0 0;
        }
        .error-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: #f0f4ff;
            color: #1f4bff;
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }
        a {
            color: #1f4bff;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="card">
        @isset($error_number)
            <div class="error-number">{{ $error_number }}</div>
        @endisset
        <h1>@yield('title', 'Onbekende fout')</h1>
        <p>@yield('description')</p>
    </div>
</body>
</html>

