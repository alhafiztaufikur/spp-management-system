const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(require('node:path').join(__dirname,'../assets/js/app.js'),'utf8');
const start=source.indexOf('function parseNumber('),end=source.indexOf('function bindNumericInput(',start);
const context=vm.createContext({});vm.runInContext(source.slice(start,end),context);let checks=0;
for(const [raw,value] of [['0',0],['15.000',15000],['325.000,50',325000.50],['325000.50',325000.50],['0,05',.05],[325000.50,325000.50]]){assert.equal(context.parseNumber(raw),value);checks++;}
for(const value of [0,1,15000,325000.50,.05,123456789.99]){assert.equal(context.parseNumber(context.formatRupiahString(value)),value);checks++;}
for(const [raw,formatted] of [['15000','15.000'],['325000,50','325.000,50'],['0,','0,'],['0,05','0,05']]){const input={value:raw,selectionStart:raw.length,setSelectionRange(){}};context.formatNumericInput(input);assert.equal(input.value,formatted);checks++;}
console.log('PASS '+checks+' payment number formatting and decimal round trips');
