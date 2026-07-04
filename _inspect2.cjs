const XLSX = require('xlsx');
const path = process.argv[2];
const wb = XLSX.readFile(path);
wb.SheetNames.forEach(name => {
  console.log('=== SHEET:', name, '===');
  const ws = wb.Sheets[name];
  const data = XLSX.utils.sheet_to_json(ws, {header:1, defval:''});
  console.log('Rows:', data.length);
  data.slice(0,15).forEach(r => console.log(JSON.stringify(r)));
});
