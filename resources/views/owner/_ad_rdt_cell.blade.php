{{--
  RTS / DEL / INT na cell ng isang delivery row sa expand (campaign, ad set, ad, "Walang ad id", Total), kaparehong
  itsura ng jnt_rdt na cell ng page row. $rdt = ang JS expression ng { rts, del, int: { cnt, pct } } (null = walang shipped).
--}}
<div>
  <template x-if="!({{ $rdt }})"><span style="color:#cbd5e1;font-size:11px;">—</span></template>
  <template x-if="{{ $rdt }}">
    <table style="border-collapse:collapse;font-size:11px;margin:0 auto;">
      <tbody>
        <tr>
          <td style="padding:1px 6px;text-align:left;color:#94a3b8;font-size:9px;font-weight:700;letter-spacing:0.04em;">RTS</td>
          <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;color:#111;font-weight:700" x-text="Number(({{ $rdt }}).rts.pct).toFixed(1)+'%'"></td>
          <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;color:#64748b;" x-text="num(({{ $rdt }}).rts.cnt)"></td>
        </tr>
        <tr>
          <td style="padding:1px 6px;text-align:left;color:#94a3b8;font-size:9px;font-weight:700;letter-spacing:0.04em;">DEL</td>
          <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;color:#111;font-weight:600" x-text="Number(({{ $rdt }}).del.pct).toFixed(1)+'%'"></td>
          <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;color:#64748b;" x-text="num(({{ $rdt }}).del.cnt)"></td>
        </tr>
        <tr>
          <td style="padding:1px 6px;text-align:left;color:#94a3b8;font-size:9px;font-weight:700;letter-spacing:0.04em;">INT</td>
          <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;color:#111;font-weight:600" x-text="Number(({{ $rdt }}).int.pct).toFixed(1)+'%'"></td>
          <td style="padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;color:#64748b;" x-text="num(({{ $rdt }}).int.cnt)"></td>
        </tr>
      </tbody>
    </table>
  </template>
</div>
