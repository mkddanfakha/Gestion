@include('vendor.mail.html.layout', [
    'header' => view('vendor.mail.html.header', ['url' => config('app.url')])->render(),
    'slot' => view('vendor.mail.html.message-content', [
        'greeting' => $greeting ?? null,
        'level' => $level ?? 'success',
        'introLines' => $introLines ?? [],
        'actionText' => $actionText ?? null,
        'actionUrl' => $actionUrl ?? null,
        'displayableActionUrl' => $displayableActionUrl ?? $actionUrl ?? null,
        'outroLines' => $outroLines ?? [],
        'salutation' => $salutation ?? null,
    ])->render(),
    'subcopy' => isset($actionText) ? '<p style="font-size: 12px; line-height: 1.5em; color: #AEAEAE; margin-top: 20px;">Si vous avez des difficultés à cliquer sur le bouton "' . ($actionText ?? '') . '", copiez et collez l\'URL ci-dessous dans votre navigateur web : <a href="' . ($actionUrl ?? '') . '" style="color: #0d6efd; text-decoration: underline;">' . ($displayableActionUrl ?? $actionUrl ?? '') . '</a></p>' : null,
    'footer' => view('vendor.mail.html.footer')->render(),
])
