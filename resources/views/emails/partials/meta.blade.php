{{--
  Key/value block for document facts (number, amount, date).
  $rows  array<int, array{label: string, value: string, strong?: bool, red?: bool, wrap?: bool}>
--}}
@php
  $mono = "ui-monospace, Menlo, Consolas, 'Liberation Mono', monospace";
  $sans = "'Helvetica Neue', Helvetica, Arial, sans-serif";
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 24px;">
  @foreach ($rows as $row)
    <tr>
      <td style="padding:10px 0; {{ $loop->first ? 'border-top:1px solid #141210; ' : '' }}border-bottom:1px solid #e3dccd; font-family:{{ $sans }}; font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:#7a7367; width:45%;">
        {{ $row['label'] }}
      </td>
      <td align="right" style="padding:10px 0; {{ $loop->first ? 'border-top:1px solid #141210; ' : '' }}border-bottom:1px solid #e3dccd; font-family:{{ $mono }}; font-size:{{ ($row['strong'] ?? false) ? '18px' : '14px' }}; font-weight:{{ ($row['strong'] ?? false) ? '700' : '400' }}; color:{{ ($row['red'] ?? false) ? '#c8341f' : '#141210' }}; white-space:{{ ($row['wrap'] ?? false) ? 'normal' : 'nowrap' }};">
        {{ $row['value'] }}
      </td>
    </tr>
  @endforeach
</table>
