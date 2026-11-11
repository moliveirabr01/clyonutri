<?php
// NI2-687: gera public/assets/css/ds-utilities.css (utilitários do design system, só tokens).
// Uso: php tests/tools/ds_utilities_gen.php > public/assets/css/ds-utilities.css
declare(strict_types=1);
$I = ' !important';
$out = [];
$out[] = "/*\n * Clyo Nutri — utilitários do design system (NI2-687). Gerado por tests/tools/ds_utilities_gen.php; não edite à mão.\n * Substituem os style=\"\" das telas antigas usando só os tokens do clinical-botanical.css.\n * Espaço: escala --space-*; texto: --font-size-*; cor, fundo e borda: tokens de marca, neutros e semânticos.\n * Os de display não têm !important porque o JS das telas ainda alterna element.style.display.\n */";
$sp = ['0' => '0', '1' => 'var(--space-1)', '2' => 'var(--space-2)', '3' => 'var(--space-3)', '4' => 'var(--space-4)', '5' => 'var(--space-5)', '6' => 'var(--space-6)', '8' => 'var(--space-8)', '10' => 'var(--space-10)', '12' => 'var(--space-12)', '16' => 'var(--space-16)'];
foreach (['m' => 'margin', 'p' => 'padding'] as $k => $prop) {
    foreach ($sp as $n => $v) {
        $out[] = ".u-$k-$n{{$prop}:$v$I}";
        $out[] = ".u-{$k}x-$n{{$prop}-left:$v$I;{$prop}-right:$v$I}";
        $out[] = ".u-{$k}y-$n{{$prop}-top:$v$I;{$prop}-bottom:$v$I}";
        foreach (['t' => 'top', 'r' => 'right', 'b' => 'bottom', 'l' => 'left'] as $s => $side) $out[] = ".u-$k$s-$n{{$prop}-$side:$v$I}";
    }
}
$out[] = ".u-m-auto{margin:auto$I}.u-mx-auto{margin-left:auto$I;margin-right:auto$I}.u-mt-auto{margin-top:auto$I}.u-ml-auto{margin-left:auto$I}.u-mr-auto{margin-right:auto$I}";
foreach ($sp as $n => $v) $out[] = ".u-gap-$n{gap:$v$I}";
// display (sem !important)
$out[] = ".u-flex{display:flex}.u-inline-flex{display:inline-flex}.u-grid{display:grid}.u-block{display:block}.u-inline-block{display:inline-block}.u-inline{display:inline}.u-hidden{display:none}";
$out[] = ".u-flex-col{flex-direction:column$I}.u-wrap{flex-wrap:wrap$I}.u-flex-1{flex:1 1 0$I;min-width:0}.u-flex-0{flex-grow:0$I}.u-shrink-0{flex-shrink:0$I}";
$out[] = ".u-basis-sm{flex-basis:calc(var(--space-10) * 3)$I}.u-basis-md{flex-basis:calc(var(--space-10) * 5)$I}.u-basis-lg{flex-basis:calc(var(--space-10) * 8)$I}";
$out[] = ".u-items-center{align-items:center$I}.u-items-start{align-items:flex-start$I}.u-items-end{align-items:flex-end$I}.u-items-baseline{align-items:baseline$I}.u-items-stretch{align-items:stretch$I}";
$out[] = ".u-justify-between{justify-content:space-between$I}.u-justify-center{justify-content:center$I}.u-justify-end{justify-content:flex-end$I}.u-justify-start{justify-content:flex-start$I}";
$out[] = ".u-self-end{align-self:end$I}.u-self-center{align-self:center$I}.u-self-start{align-self:start$I}.u-content-start{align-content:start$I}";
// grades que se reorganizam (reflow WCAG 1.4.10)
$out[] = ".u-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))$I}.u-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))$I}.u-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))$I}.u-cols-aside{grid-template-columns:minmax(0,1fr) calc(var(--space-12) * 5)$I}";
$out[] = ".u-cols-auto-sm{grid-template-columns:repeat(auto-fit,minmax(min(100%,calc(var(--space-10) * 3.5)),1fr))$I}.u-cols-auto-md{grid-template-columns:repeat(auto-fit,minmax(min(100%,calc(var(--space-10) * 5)),1fr))$I}.u-cols-auto-lg{grid-template-columns:repeat(auto-fit,minmax(min(100%,calc(var(--space-10) * 6.5)),1fr))$I}";
$out[] = "@media (max-width:900px){.u-cols-3,.u-cols-4{grid-template-columns:repeat(2,minmax(0,1fr))$I}.u-cols-aside{grid-template-columns:minmax(0,1fr)$I}}";
$out[] = "@media (max-width:640px){.u-cols-2,.u-cols-3,.u-cols-4{grid-template-columns:minmax(0,1fr)$I}}";
// tipografia
foreach (['caption', 'compact', 'body', 'card-title', 'h3', 'h2', 'h1'] as $f) $out[] = ".u-text-$f{font-size:var(--font-size-$f)$I}";
foreach (['regular', 'medium', 'semibold', 'bold'] as $w) $out[] = ".u-font-$w{font-weight:var(--font-weight-$w)$I}";
$out[] = ".u-font-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace$I}.u-leading-tight{line-height:var(--line-height-tight)$I}.u-leading-body{line-height:var(--line-height-body)$I}.u-tracking-wide{letter-spacing:.05em$I}.u-uppercase{text-transform:uppercase$I}";
$out[] = ".u-text-center{text-align:center$I}.u-text-left{text-align:left$I}.u-text-right{text-align:right$I}.u-no-underline{text-decoration:none$I}.u-underline{text-decoration:underline$I}";
$out[] = ".u-nowrap{white-space:nowrap$I}.u-pre-line{white-space:pre-line$I}.u-pre-wrap{white-space:pre-wrap$I}.u-ws-normal{white-space:normal$I}.u-ellipsis{overflow:hidden$I;text-overflow:ellipsis$I;white-space:nowrap$I}.u-break{overflow-wrap:anywhere$I}";
// cor
$colors = ['botanical-forest-900', 'botanical-green-600', 'botanical-green-700', 'botanical-copper-600', 'botanical-copper-700', 'brand-copper-500', 'neutral-gray-400', 'neutral-gray-500', 'neutral-gray-600', 'neutral-gray-700', 'neutral-ink-900', 'neutral-white', 'semantic-error', 'semantic-error-strong', 'semantic-success', 'semantic-success-text', 'semantic-warning', 'semantic-warning-strong', 'semantic-info'];
foreach ($colors as $c) $out[] = ".u-color-$c{color:var(--$c)$I}";
$out[] = ".u-color-inherit{color:inherit$I}";
$out[] = ".u-link{color:var(--botanical-copper-600)$I}.u-link:hover{color:var(--botanical-copper-700)$I}";
$bgs = ['neutral-white', 'neutral-gray-50', 'neutral-gray-100', 'neutral-gray-200', 'botanical-cream-50', 'botanical-sage-100', 'botanical-copper-100', 'semantic-error-light', 'semantic-success-light', 'semantic-warning-light', 'semantic-info-light', 'semantic-warning-border'];
foreach ($bgs as $b) $out[] = ".u-bg-$b{background:var(--$b)$I}";
$out[] = ".u-bg-none{background:transparent$I}";
// borda
$out[] = ".u-border{border:1px solid var(--u-bc,var(--neutral-gray-200))$I}.u-border-2{border:2px solid var(--u-bc,var(--neutral-gray-200))$I}.u-border-0{border:0$I}";
$out[] = ".u-border-t{border-top:1px solid var(--u-bc,var(--neutral-gray-200))$I}.u-border-b{border-bottom:1px solid var(--u-bc,var(--neutral-gray-200))$I}.u-border-b-2{border-bottom:2px solid var(--u-bc,var(--neutral-gray-200))$I}.u-border-t-0{border-top:0$I}.u-border-b-0{border-bottom:0$I}.u-border-dashed{border-style:dashed$I}";
$bcs = ['border-input', 'neutral-gray-100', 'neutral-gray-300', 'neutral-gray-400', 'botanical-sage-100', 'botanical-sage-200', 'botanical-copper-100', 'botanical-copper-600', 'brand-copper-500', 'semantic-error', 'semantic-error-border', 'semantic-success', 'semantic-success-border', 'semantic-success-light', 'semantic-warning', 'semantic-warning-border', 'semantic-info', 'semantic-info-border', 'transparent'];
foreach ($bcs as $b) $out[] = ".u-bc-$b{--u-bc:" . ($b === 'transparent' ? 'transparent' : "var(--$b)") . ";border-color:" . ($b === 'transparent' ? 'transparent' : "var(--$b)") . "$I}";
$out[] = ".u-accent{border-left:4px solid var(--u-accent,var(--neutral-gray-300))$I}.u-accent-top{border-top:4px solid var(--u-accent,var(--neutral-gray-300))$I}";
foreach (['botanical-copper-600', 'botanical-green-600', 'brand-copper-500', 'semantic-error', 'semantic-info', 'semantic-success', 'semantic-warning'] as $a) $out[] = ".u-accent-$a{--u-accent:var(--$a)}";
foreach (['0' => '0', 'input' => 'var(--radius-input)', 'button' => 'var(--radius-button)', 'card' => 'var(--radius-card)', 'modal' => 'var(--radius-modal)', 'pill' => 'var(--radius-pill)'] as $k => $v) $out[] = ".u-radius-$k{border-radius:$v$I}";
foreach (['sm', 'md', 'lg'] as $s) $out[] = ".u-shadow-$s{box-shadow:var(--shadow-$s)$I}";
// tamanho
$out[] = ".u-w-full{width:100%$I}.u-w-auto{width:auto$I}.u-w-15{width:15%$I}.u-w-20{width:20%$I}.u-w-25{width:25%$I}.u-w-40{width:40%$I}.u-maxw-full{max-width:100%$I}";
$out[] = ".u-maxw-narrow{max-width:calc(var(--space-10) * 9)$I}.u-maxw-form{max-width:calc(var(--space-10) * 14)$I}.u-maxw-prose{max-width:calc(var(--space-10) * 18)$I}.u-maxw-wide{max-width:calc(var(--space-10) * 21)$I}.u-maxw-page{max-width:calc(var(--space-10) * 26)$I}";
$out[] = ".u-minw-touch{min-width:var(--touch-target-min)$I}.u-minw-sm{min-width:min(calc(var(--space-10) * 3),100%)$I}.u-minw-md{min-width:min(calc(var(--space-10) * 4.5),100%)$I}.u-minw-lg{min-width:min(calc(var(--space-10) * 5.5),100%)$I}";
$out[] = ".u-minh-touch{min-height:var(--touch-target-min)$I}.u-minh-field{min-height:var(--space-16)$I}.u-minh-area{min-height:calc(var(--space-10) * 4)$I}.u-maxh-dialog{max-height:80vh$I}.u-maxh-list{max-height:calc(var(--space-12) * 4.5)$I}";
// comportamento
$out[] = ".u-overflow-hidden{overflow:hidden$I}.u-overflow-auto{overflow:auto$I}.u-overflow-x-auto{overflow-x:auto$I}.u-overflow-y-auto{overflow-y:auto$I}.u-pointer{cursor:pointer$I}.u-disabled{opacity:.55$I;cursor:not-allowed$I}";
$out[] = ".u-list-none{list-style:none$I}.u-collapse{border-collapse:collapse$I}.u-relative{position:relative$I}.u-scroll-margin{scroll-margin-top:calc(var(--space-20) + var(--space-2))$I}.u-align-middle{vertical-align:middle$I}.u-align-top{vertical-align:top$I}";
echo implode("\n", $out), "\n";
