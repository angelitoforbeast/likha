<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>No access</title>
  <style>
    body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
           background: #f3f4f6; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; color: #111827; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,.05);
            padding: 2rem; max-width: 28rem; margin: 1rem; }
    h1 { font-size: 1.125rem; margin: 0 0 .5rem; }
    p { margin: 0 0 1rem; color: #4b5563; font-size: .9rem; }
    a { color: #2563eb; font-size: .875rem; text-decoration: none; }
    a:hover { text-decoration: underline; }
  </style>
</head>
<body>
  <div class="card">
    <h1>No access</h1>
    <p>You don't have access to this page. Ask your OIC or the owner.</p>
    <a href="{{ url('/') }}">← Back to home</a>
  </div>
</body>
</html>
