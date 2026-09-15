{{--
  Shared HTML shell for outgoing mails (invoice, estimate, reminder).

  Table-based and fully inline-styled so it survives Outlook/Gmail. Uses the
  Ledger palette (paper / sheet / ink) with system fonts, since mail clients
  will not load the app's webfonts.

  Sections:  kind     small uppercase label top-right, e.g. "Rechnung"
             content  the message body (paragraphs + optional meta table)
  Vars:      $profile BusinessProfile
--}}
@php
  $senderLines = array_values(array_filter([
      $profile->address_line_1,
      $profile->address_line_2,
      trim(($profile->postal_code ?? '').' '.($profile->city ?? '')),
  ]));
  $sans = "'Helvetica Neue', Helvetica, Arial, sans-serif";
  $mono = "ui-monospace, Menlo, Consolas, 'Liberation Mono', monospace";
@endphp
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <meta name="supported-color-schemes" content="light">
  <title>@yield('title')</title>
  <style>
    body { margin: 0; padding: 0; background: #f7f3ea; -webkit-text-size-adjust: 100%; }
    table { border-collapse: collapse; }
    p { margin: 0 0 14px; }
    a { color: #141210; }
    @media (max-width: 620px) {
      .wrap { padding: 16px !important; }
      .card-pad { padding-left: 24px !important; padding-right: 24px !important; }
    }
  </style>
</head>
<body style="margin:0; padding:0; background:#f7f3ea;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7f3ea;">
    <tr>
      <td class="wrap" align="center" style="padding:40px 16px;">
        <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:560px; max-width:100%; background:#fbf9f4; border:1px solid #141210;">

          {{-- Head --}}
          <tr>
            <td class="card-pad" style="padding:20px 40px; border-bottom:1px solid #141210;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="font-family:{{ $sans }}; font-size:15px; font-weight:700; color:#141210; letter-spacing:-0.01em;">
                    {{ $profile->name }}
                  </td>
                  <td align="right" style="font-family:{{ $mono }}; font-size:11px; letter-spacing:.14em; text-transform:uppercase; color:#7a7367; white-space:nowrap;">
                    @yield('kind')
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- Body --}}
          <tr>
            <td class="card-pad" style="padding:32px 40px 24px; font-family:{{ $sans }}; font-size:15px; line-height:1.55; color:#141210;">
              @yield('content')
            </td>
          </tr>

          {{-- Foot --}}
          <tr>
            <td class="card-pad" style="padding:18px 40px 22px; border-top:1px solid #141210; font-family:{{ $mono }}; font-size:12px; line-height:1.6; color:#7a7367;">
              <span style="color:#141210;">{{ $profile->name }}</span>
              @foreach ($senderLines as $line)
                <br>{{ $line }}
              @endforeach
              @if ($profile->email)
                <br><a href="mailto:{{ $profile->email }}" style="color:#7a7367; text-decoration:none;">{{ $profile->email }}</a>
              @endif
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
