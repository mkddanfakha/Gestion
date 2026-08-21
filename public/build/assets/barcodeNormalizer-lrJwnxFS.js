function r(t){return t.replace(/[\u0000-\u001F\u007F\u200B-\u200D\uFEFF]/g,"").trim()}function n(t){const o=r(t);return o.length>0&&/^[A-Za-z0-9]+$/.test(o)}export{n as i,r as n};
