"use strict";
const $ = (id) => document.getElementById(id);
const portalRoot = new URL(".", document.currentScript.src);
let csrf = "", state = null, page = "overview", adjustmentClient = null, refundOperation = null;
const titles = {overview:"Overview", clients:"Clients & hardware", tariffs:"Device tariffs", vouchers:"Credit vouchers", updates:"App updates", notifications:"Notifications", operations:"Unlock operations", ledger:"Unit ledger & audit"};
const fmt = (value) => Number(value).toLocaleString();
const when = (value) => value ? new Date(Number(value)*1000).toLocaleString() : "—";
const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (x) => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[x]));
function badge(value) { const color = ["active","applied"].includes(value) ? "green" : ["authorized","executing"].includes(value) ? "orange" : ["inactive","failed"].includes(value) ? "red" : ""; return `<span class="pill ${color}">${esc(value)}</span>`; }
function notice(message, bad=false) { $("notice").textContent=message; $("notice").classList.toggle("bad",bad); $("notice").hidden=false; }
function showLogin() { csrf=""; state=null; $("voucher-codes").value=""; $("dashboard").hidden=true; $("login-screen").hidden=false; document.querySelectorAll("dialog[open]").forEach(x=>x.close()); }
async function api(path, method="GET", data=null) {
  const headers={"Accept":"application/json"}; if(data!==null) headers["Content-Type"]="application/json"; if(method!=="GET" && csrf) headers["X-CSRF-Token"]=csrf;
  let response; try { response=await fetch(new URL("api/admin/"+path,portalRoot),{method,headers,credentials:"same-origin",body:data===null?undefined:JSON.stringify(data)}); } catch { throw new Error("The API could not be reached. Your unsent changes have been retained."); }
  const result=await response.json(); if(!response.ok || !result.success) { if(response.status===401 && path!=="login") showLogin(); throw new Error(result.message || "The request failed."); } return result;
}
async function refresh() { try { state=await api("data"); render(); $("connection-status").textContent="API connected"; $("connection-status").className="pill green"; $("updated-label").textContent="Updated "+new Date().toLocaleTimeString(); } catch(error) { $("connection-status").textContent="API unavailable"; $("connection-status").className="pill orange"; notice(error.message,true); } }
function navigate(next) { page=next; $("page-title").textContent=titles[next]; document.querySelectorAll("[data-section]").forEach(x=>x.hidden=x.dataset.section!==next); document.querySelectorAll(".nav").forEach(x=>x.classList.toggle("active",x.dataset.page===next)); }
function render() {
  const s=state.summary;
  $("stats").innerHTML=[["Client accounts",s.clients,`${fmt(s.activeClients)} active accounts`],["Available client units",s.availableUnits,"Combined current balances"],["Consumed units",s.consumedUnits,"Completed, non-refunded unlocks"],["Reserved units",s.reservedUnits,"Authorized or executing operations"]].map(([name,value,note])=>`<article class="stat"><small>${esc(name)}</small><strong>${fmt(value)}</strong><span>${esc(note)}</span></article>`).join("");
  $("recent-operations").innerHTML=state.operations.slice(0,6).map(o=>`<div class="operation-mini"><div><b>${esc(o.device_name)}</b><small>${esc(o.username)} · ${esc(when(o.created_at))}</small></div><div>${badge(o.state)}<small>${fmt(o.cost)} units</small></div></div>`).join("") || '<div class="empty">Unlock activity will appear here.</div>';
  renderClients(); renderTariffs(); renderOperations(); renderVouchers(); renderClientControl();
  $("ledger-body").innerHTML=state.ledger.map(l=>`<tr><td>${esc(when(l.created_at))}</td><td>${esc(l.username)}</td><td>${badge(l.kind.toLowerCase())}</td><td class="${l.delta>0?"positive":"negative"}">${l.delta>0?"+":""}${fmt(l.delta)}</td><td>${fmt(l.balance_after)}</td><td class="reason-cell">${esc(l.reason)}</td></tr>`).join("") || '<tr><td colspan="6" class="empty">No unit transactions.</td></tr>';
  $("audit-list").innerHTML=state.audit.map(a=>`<div class="audit-entry"><time>${esc(when(a.created_at))}</time><div><b>${esc(a.action.replaceAll("_"," "))}</b><span class="muted">${esc(a.actor)} · ${esc(a.message)}</span></div></div>`).join("") || '<p class="muted">No administrative activity.</p>';
}
function renderClients() {
  const query=$("client-search").value.toLowerCase();
  $("clients-body").innerHTML=state.clients.filter(c=>`${c.username} ${c.displayName} ${c.hwid}`.toLowerCase().includes(query)).map(c=>`<tr><td><b>${esc(c.displayName)}</b><small>${esc(c.username)}</small></td><td>${badge(c.active && (!c.expiresAt || c.expiresAt>Date.now()/1000)?"active":"inactive")}${c.expiresAt?`<small>Until ${esc(when(c.expiresAt))}</small>`:""}</td><td><b>${fmt(c.units)}</b></td><td><span class="monospace" title="${esc(c.hwid)}">${esc(c.hwid.slice(0,10))}…${esc(c.hwid.slice(-6))}</span><br><button data-copy-hwid="${esc(c.id)}" class="link">Copy HWID</button></td><td><div class="actions"><button data-edit-client="${esc(c.id)}">Edit</button><button data-units-client="${esc(c.id)}">Units</button><button data-toggle-client="${esc(c.id)}">${c.active?"Suspend":"Activate"}</button></div></td></tr>`).join("") || '<tr><td colspan="5" class="empty">No matching clients.</td></tr>';
}
function renderTariffs() {
  $("tariffs-body").innerHTML=state.devices.map(d=>`<tr data-device-row="${esc(d.id)}"><td><div class="device-cell"><img src="${esc(new URL('assets/'+d.image,portalRoot).href)}" alt=""><div><b>${esc(d.name)}</b><small>${esc(d.id)}</small></div></div></td><td>${esc(d.family)}${!d.available?'<small>Original routine unavailable</small>':""}</td><td><input class="tariff-input" type="number" min="0" max="1000000" step="1" value="${d.unitCost}" aria-label="${esc(d.name)} units" ${!d.available?"disabled":""}></td><td><input class="tariff-enabled" type="checkbox" aria-label="Enable ${esc(d.name)}" ${d.enabled?"checked":""} ${!d.available?"disabled":""}></td><td><button data-save-tariff="${esc(d.id)}" ${!d.available?"disabled":""}>Save</button></td></tr>`).join("");
}
function renderOperations() {
  $("operations-body").innerHTML=state.operations.map(o=>`<tr><td><b>${esc(o.device_name)}</b><small>${esc(o.username)}</small></td><td>${esc(when(o.created_at))}<small class="monospace">${esc(o.id.slice(0,12))}</small></td><td>${fmt(o.cost)}</td><td>${badge(o.state)}</td><td class="reason-cell">${esc(o.message || (o.state==="authorized"?"Waiting for execution grant":o.state==="executing"?"Awaiting engine outcome":""))}<small>${esc(o.gateway)}</small></td><td>${["applied","executing"].includes(o.state)?`<button data-refund="${esc(o.id)}">Refund</button>`:""}</td></tr>`).join("") || '<tr><td colspan="6" class="empty">No unlock operations.</td></tr>';
}
function renderVouchers(){
  $("vouchers-body").innerHTML=(state.vouchers||[]).map(v=>`<tr><td class="monospace">${esc(v.code_hint)}<small>${esc(when(v.created_at))}</small></td><td>${fmt(v.units)}</td><td class="monospace" title="${esc(v.hwid||'')}">${v.hwid?esc(v.hwid.slice(0,10)+'…'+v.hwid.slice(-6)):'Any authorized hardware'}</td><td>${badge(v.used_at?'redeemed':!v.active?'disabled':v.expires_at&&Number(v.expires_at)<Date.now()/1000?'expired':'active')}</td><td>${esc(v.used_by||'—')}<small>${v.used_at?esc(when(v.used_at)):''}</small></td><td>${!v.used_at&&v.active?`<button data-disable-voucher="${esc(v.id)}">Disable</button>`:''}</td></tr>`).join('')||'<tr><td colspan="6" class="empty">No vouchers created.</td></tr>';
}
function renderClientControl(){
  const policy=state.updatePolicy||{};
  if(!document.activeElement?.closest('#update-form')){
    $('update-version').value=policy.version||'';$('update-build').value=policy.buildNumber||'';$('update-url').value=policy.downloadUrl||'';
    $('update-grace').value=policy.graceHours??24;$('update-notes').value=policy.notes||'';$('update-enabled').checked=!!policy.enabled;
  }
  $('update-status').textContent=policy.enabled?`Published ${when(policy.publishedAt)} · Mandatory from ${when(policy.mandatoryAt)}`:'Update enforcement is off.';
  const selected=$('notification-recipient').value;
  $('notification-recipient').innerHTML='<option value="">All clients</option>'+state.clients.map(c=>`<option value="${esc(c.id)}">${esc(c.username)} · ${esc(c.displayName)}</option>`).join('');
  $('notification-recipient').value=selected;
  $('notifications-body').innerHTML=(state.notifications||[]).map(n=>`<tr><td><b>${esc(n.title)}</b><small class="reason-cell">${esc(n.message)}</small></td><td>${esc(n.recipient_username||'All clients')}</td><td>${esc(when(n.created_at))}<small>${n.expires_at?'Expires '+esc(when(n.expires_at)):''}</small></td><td>${fmt(n.read_count)}</td><td>${Number(n.active)?`<button data-disable-notification="${esc(n.id)}">Withdraw</button>`:badge('disabled')}</td></tr>`).join('')||'<tr><td colspan="5" class="empty">No notifications sent.</td></tr>';
}
function clientPayload(c) { return {username:c.username,displayName:c.displayName,hwid:c.hwid,active:c.active,expiresAt:c.expiresAt,password:""}; }
function openClient(id=null) {
  const c=id?state.clients.find(x=>x.id===id):null; $("client-form").reset(); $("client-id").value=c?.id||""; $("client-dialog-title").textContent=c?"Edit client":"Add client";
  $("client-username").value=c?.username||""; $("client-name").value=c?.displayName||""; $("client-hwid").value=c?.hwid||""; $("client-active").checked=c?c.active:true;
  $("client-password").required=!c; $("initial-units-field").hidden=!!c; $("client-units").value=0;
  $("client-expiry").value=c?.expiresAt?new Date(c.expiresAt*1000-new Date().getTimezoneOffset()*60000).toISOString().slice(0,16):"";
  $("client-dialog").showModal();
}
async function submit(form, work) {
  const button=form.querySelector('button[type="submit"],button[data-submit]'); button.disabled=true;
  form.querySelector(".dialog-error")?.remove();
  try { await work(); } catch(error) { const message=document.createElement("p"); message.className="dialog-error"; message.setAttribute("role","alert"); message.textContent=error.message; form.append(message); } finally { button.disabled=false; }
}
$("login-form").addEventListener("submit",async event=>{event.preventDefault(); const button=event.currentTarget.querySelector("button"); button.disabled=true; $("login-error").textContent=""; try {const data=await api("login","POST",{username:$("admin-username").value.trim(),password:$("admin-password").value}); csrf=data.csrfToken; $("admin-label").textContent=data.username; $("admin-password").value=""; $("login-screen").hidden=true; $("dashboard").hidden=false; await refresh();} catch(error){$("login-error").textContent=error.message;}finally{button.disabled=false;}});
$("client-form").addEventListener("submit",event=>{event.preventDefault(); submit(event.currentTarget,async()=>{const id=$("client-id").value;const payload={username:$("client-username").value.trim(),displayName:$("client-name").value.trim(),password:$("client-password").value,hwid:$("client-hwid").value.trim(),active:$("client-active").checked,initialUnits:Number($("client-units").value),expiresAt:$("client-expiry").value?Math.floor(new Date($("client-expiry").value).getTime()/1000):null};await api("clients"+(id?"/"+id:""),id?"PUT":"POST",payload);$("client-password").value="";$("client-dialog").close();await refresh();notice("Client saved. Hardware and account rules are active.");});});
$("units-form").addEventListener("submit",event=>{event.preventDefault();submit(event.currentTarget,async()=>{const form=event.currentTarget;form.dataset.requestId ||= crypto.randomUUID();await api(`clients/${adjustmentClient.id}/units`,"POST",{delta:Number($("units-delta").value),reason:$("units-reason").value.trim(),requestId:form.dataset.requestId});delete form.dataset.requestId;$("units-dialog").close();await refresh();notice("Unit balance and ledger updated atomically.");});});
$("refund-form").addEventListener("submit",event=>{event.preventDefault();submit(event.currentTarget,async()=>{await api(`operations/${refundOperation.id}/refund`,"POST",{reason:$("refund-reason").value.trim()});$("refund-dialog").close();await refresh();notice("The operation was refunded once.");});});
$("password-form").addEventListener("submit",event=>{event.preventDefault();const form=event.currentTarget;submit(form,async()=>{await api("password","POST",{currentPassword:$("old-password").value,newPassword:$("new-password").value});form.reset();showLogin();$("login-error").textContent="Password changed. Sign in with the new password.";});});
document.addEventListener("click",async event=>{
  const button=event.target.closest("button"); if(!button) return;
  if(button.closest('form')&&(button.type==='submit'||button.hasAttribute('data-submit')))return;
  if(button.hasAttribute("data-close")){button.closest("dialog").close();return;}
  if(button.dataset.page){navigate(button.dataset.page);return;}
  if(button.hasAttribute("data-create-client")){openClient();return;}
  if(button.dataset.editClient){openClient(button.dataset.editClient);return;}
  if(button.dataset.unitsClient){adjustmentClient=state.clients.find(c=>c.id===button.dataset.unitsClient);$("units-form").reset();delete $("units-form").dataset.requestId;$("units-client").textContent=`${adjustmentClient.username} · ${fmt(adjustmentClient.units)} available units`;$("units-dialog").showModal();return;}
  if(button.dataset.refund){refundOperation=state.operations.find(o=>o.id===button.dataset.refund);$("refund-form").reset();$("refund-description").textContent=`Refund ${fmt(refundOperation.cost)} units to ${refundOperation.username} for ${refundOperation.device_name}.`;$("refund-dialog").showModal();return;}
  try {
    if(button.dataset.copyHwid){await navigator.clipboard.writeText(state.clients.find(c=>c.id===button.dataset.copyHwid).hwid);notice("Authorized HWID copied.");}
    if(button.dataset.disableVoucher){button.disabled=true;await api('vouchers/'+button.dataset.disableVoucher+'/disable','POST',{});await refresh();notice('Voucher disabled.');}
    if(button.dataset.disableNotification){button.disabled=true;await api('notifications/'+button.dataset.disableNotification+'/disable','POST',{});await refresh();notice('Notification withdrawn.');}
    if(button.dataset.toggleClient){button.disabled=true;const c=state.clients.find(x=>x.id===button.dataset.toggleClient);await api("clients/"+c.id,"PUT",{...clientPayload(c),active:!c.active});await refresh();notice(c.active?"Client suspended and sessions revoked.":"Client activated.");}
    if(button.dataset.saveTariff){button.disabled=true;const row=button.closest("tr");await api("devices/"+button.dataset.saveTariff,"PUT",{unitCost:Number(row.querySelector(".tariff-input").value),enabled:row.querySelector(".tariff-enabled").checked});await refresh();notice("Device tariff saved. New unlocks use the server's updated price.");}
  }catch(error){notice(error.message,true);}finally{button.disabled=false;}
});
$("client-search").addEventListener("input",()=>{if(state)renderClients();});
$("refresh-button").addEventListener("click",refresh);
$("password-button").addEventListener("click",()=>{$("password-form").reset();$("password-dialog").showModal();});
$("logout-button").addEventListener("click",async()=>{try{await api("logout","POST",{});}finally{showLogin();}});
(async()=>{try{const session=await api("session");csrf=session.csrfToken;$("admin-label").textContent=session.username;$("login-screen").hidden=true;$("dashboard").hidden=false;await refresh();}catch{showLogin();}})();
setInterval(()=>{if(csrf && !document.querySelector("dialog[open]"))refresh();},30000);
$("create-vouchers").addEventListener('click',()=>{$('voucher-form').reset();$('voucher-dialog').showModal();});
$("voucher-form").addEventListener('submit',event=>{event.preventDefault();submit(event.currentTarget,async()=>{const response=await api('vouchers','POST',{units:Number($('voucher-units').value),quantity:Number($('voucher-quantity').value),hwid:$('voucher-hwid').value.trim(),expiresAt:$('voucher-expiry').value?Math.floor(new Date($('voucher-expiry').value).getTime()/1000):null,notes:$('voucher-notes').value.trim()});$('voucher-dialog').close();$('voucher-codes').value=response.codes.join('\n');$('codes-dialog').showModal();await refresh();});});
$("copy-voucher-codes").addEventListener('click',async()=>{await navigator.clipboard.writeText($('voucher-codes').value);});
$('update-form').addEventListener('submit',event=>{event.preventDefault();submit(event.currentTarget,async()=>{
  await api('update-policy','PUT',{enabled:$('update-enabled').checked,version:$('update-version').value.trim(),buildNumber:Number($('update-build').value),downloadUrl:$('update-url').value.trim(),graceHours:Number($('update-grace').value),notes:$('update-notes').value.trim()});
  await refresh();notice('Update policy published. The grace period starts now.');
});});
$('notification-form').addEventListener('submit',event=>{event.preventDefault();submit(event.currentTarget,async()=>{
  await api('notifications','POST',{recipientId:$('notification-recipient').value||null,title:$('notification-title').value.trim(),message:$('notification-message').value.trim(),expiresAt:$('notification-expiry').value?Math.floor(new Date($('notification-expiry').value).getTime()/1000):null});
  $('notification-form').reset();await refresh();notice('Notification sent.');
});});
$('send-notification').addEventListener('click',event=>{event.preventDefault();$('notification-form').requestSubmit();});
