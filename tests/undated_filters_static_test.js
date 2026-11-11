'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
if(!process.env.SPP_CSS_TOOLS)throw Error('Set SPP_CSS_TOOLS to the external CSS parser modules directory.');
const cssTree=require(path.join(process.env.SPP_CSS_TOOLS,'css-tree')),
 postcss=require(path.join(process.env.SPP_CSS_TOOLS,'postcss')),
 selectorParser=require(path.join(process.env.SPP_CSS_TOOLS,'postcss-selector-parser'));
const root=path.resolve(__dirname,'..');let selectors=0;
for(const name of ['desktop_transaction_refinements','global_report_matrix','menu_refinements','payment_workspace_refinement']){
 const css=fs.readFileSync(path.join(root,'assets/css',name+'.css'),'utf8'),errors=[];
 cssTree.parse(css,{onParseError:error=>errors.push(error.message)});assert.deepEqual(errors,[],name+' CSS syntax');
 postcss.parse(css).walkRules(rule=>{selectorParser().astSync(rule.selector);selectors++;});
 assert(!/report-undated|ph-legacy-filter|du-history-notice|du-history-count|report-period-options/.test(css),name+' retired selectors removed');
}
for(const file of ['pembayaran/lihat.php','includes/registration_combined_page.php','laporan/index.php','laporan/template.php']){
 const php=fs.readFileSync(path.join(root,file),'utf8');
 assert(!/name=["'](?:include_undated|undated)["']|ph-legacy-filter|du-history-notice|report-undated-field|report-period-options/.test(php),file+' obsolete controls and wrappers removed');
 if(file==='laporan/index.php')assert(!/name=["']all_periods["']/.test(php),'Transaction report has only date-range filtering');
}
console.log('PASS four CSS parsers,',selectors,'selectors and four public-page control detectors');
