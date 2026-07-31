const root = document.querySelector('#app');
const token = localStorage.getItem('token');
const headers = { Accept: 'application/json', 'Content-Type': 'application/json', ...(token && { Authorization: `Bearer ${token}` }) };
const esc = value => String(value).replace(/[&<>'"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[c]);
const notice = (text, bad = false) => `<p role="status" class="mb-4 rounded-lg p-3 ${bad ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700'}">${esc(text)}</p>`;

async function api(path, options = {}) {
    const response = await fetch(`/api/v1${path}`, { ...options, headers });
    const body = response.status === 204 ? null : await response.json();
    if (!response.ok) throw new Error(response.status === 401 ? 'Sesi berakhir. Silakan masuk kembali.' : body.message || Object.values(body.errors || {}).flat().join(' ') || 'Permintaan gagal.');
    return body;
}

async function RolePermissionPage() {
    let data;
    try { data = await api('/roles'); } catch (error) { root.innerHTML = notice(error.message, true); return; }
    let selected;
    const render = (message = '') => {
        const role = data.roles.find(item => item.id === selected);
        const checked = new Set(role?.permissions.map(item => item.name) || []);
        root.innerHTML = `<header class="mb-6"><h1 class="text-2xl font-bold">Role & Izin</h1><p class="text-slate-600">Kelola akses pengguna portal.</p></header>${message}<div class="grid gap-6 lg:grid-cols-[18rem_1fr]"><section class="card"><div class="mb-4 flex justify-between"><h2 class="font-semibold">Role</h2><button id="new" class="btn">Tambah</button></div><ul>${data.roles.map(item => `<li><button data-id="${item.id}" class="w-full rounded-lg px-3 py-2 text-left ${item.id === selected ? 'bg-indigo-50 text-indigo-700' : 'hover:bg-slate-50'}">${esc(item.name)}</button></li>`).join('')}</ul></section><form id="form" class="card"><h2 class="mb-4 font-semibold">${role ? 'Ubah role' : 'Role baru'}</h2><label class="label" for="name">Nama</label><input id="name" name="name" class="field mb-5" required maxlength="255" value="${esc(role?.name || '')}" ${role?.name === 'superadmin' ? 'disabled' : ''}><fieldset ${role?.name === 'superadmin' ? 'disabled' : ''}><legend class="mb-2 text-sm font-medium">Izin</legend><div class="grid gap-2 sm:grid-cols-2">${data.permissions.map(p => `<label class="flex gap-2 rounded-lg border p-3"><input type="checkbox" name="permissions" value="${esc(p.name)}" ${checked.has(p.name) ? 'checked' : ''}> <span>${esc(p.name)}</span></label>`).join('')}</div></fieldset><div class="mt-5 flex gap-3"><button class="btn" ${role?.name === 'superadmin' ? 'disabled' : ''}>Simpan</button>${role && role.name !== 'superadmin' ? '<button id="delete" type="button" class="btn btn-danger">Hapus</button>' : ''}</div></form></div>`;
        root.querySelectorAll('[data-id]').forEach(button => button.onclick = () => { selected = Number(button.dataset.id); render(); });
        root.querySelector('#new').onclick = () => { selected = undefined; render(); };
        root.querySelector('#form').onsubmit = async event => {
            event.preventDefault(); const form = new FormData(event.currentTarget);
            try { const saved = await api(role ? `/roles/${role.id}` : '/roles', { method: role ? 'PATCH' : 'POST', body: JSON.stringify({ name: form.get('name'), permissions: form.getAll('permissions') }) }); if (role) data.roles[data.roles.findIndex(x => x.id === role.id)] = saved; else data.roles.push(saved); data.roles.sort((a, b) => a.name.localeCompare(b.name)); selected = saved.id; render(notice('Role tersimpan.')); } catch (error) { render(notice(error.message, true)); }
        };
        root.querySelector('#delete')?.addEventListener('click', async () => { if (!confirm(`Hapus role ${role.name}?`)) return; try { await api(`/roles/${role.id}`, { method: 'DELETE' }); data.roles = data.roles.filter(x => x.id !== role.id); selected = undefined; render(notice('Role dihapus.')); } catch (error) { render(notice(error.message, true)); } });
    };
    render();
}

async function SettingsPage() {
    let settings;
    try { settings = await api('/settings'); } catch (error) { root.innerHTML = notice(error.message, true); return; }
    const render = (message = '') => {
        root.innerHTML = `<header class="mb-6"><h1 class="text-2xl font-bold">Pengaturan</h1><p class="text-slate-600">Konfigurasi portal.</p></header>${message}<form id="form" class="card max-w-2xl"><div class="space-y-4">${Object.entries(settings).map(([key, value]) => `<label class="block"><span class="label">${esc(key.replaceAll('_', ' '))}</span>${typeof value === 'boolean' ? `<input type="checkbox" name="${esc(key)}" ${value ? 'checked' : ''}>` : `<input class="field" name="${esc(key)}" value="${esc(value ?? '')}">`}</label>`).join('')}</div><button class="btn mt-6">Simpan pengaturan</button></form>`;
        root.querySelector('#form').onsubmit = async event => { event.preventDefault(); const form = new FormData(event.currentTarget); const next = {}; for (const [key, value] of Object.entries(settings)) { const input = event.currentTarget.elements.namedItem(key); next[key] = typeof value === 'boolean' ? input.checked : typeof value === 'number' ? Number(form.get(key)) : form.get(key); } try { settings = await api('/settings', { method: 'PUT', body: JSON.stringify({ settings: next }) }); render(notice('Pengaturan tersimpan.')); } catch (error) { render(notice(error.message, true)); } };
    };
    render();
}

if (root) (root.dataset.page === 'roles' ? RolePermissionPage : SettingsPage)();
