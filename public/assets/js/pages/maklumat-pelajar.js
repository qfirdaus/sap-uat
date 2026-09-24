document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.student-identity').forEach(function (identity) {
    const name = identity.querySelector('h5')?.textContent.trim() || '-';
    const matric = identity.querySelector('.student-matric')?.textContent.trim() || '-';
    const label = document.createElement('span'); label.className = 'student-identity-label';
    const icon = document.createElement('i'); icon.className = 'ri-user-3-line';
    label.append(icon, document.createTextNode('Maklumat Pelajar'));
    const value = document.createElement('b'); value.textContent = name;
    const code = document.createElement('small'); code.textContent = matric;
    identity.replaceChildren(label, value, code);
  });
  document.querySelectorAll('.status-active').forEach(function (status) {
    const isActive = status.textContent.trim().toLocaleUpperCase('ms-MY') === 'AKTIF';
    status.classList.toggle('is-active', isActive);
    status.classList.toggle('is-inactive', !isActive);
  });
  document.querySelectorAll('.student-photo').forEach(function (image) {
    image.addEventListener('error', function () {
      if (image.dataset.jpeg) { image.src = image.dataset.jpeg; delete image.dataset.jpeg; return; }
      image.src = image.dataset.fallback || '';
    });
  });
  document.querySelectorAll('.student-nav a, .student-top-nav a').forEach(function (link) {
    link.addEventListener('click', function (event) {
      const target = document.querySelector(link.getAttribute('href') || '');
      if (target) {
        event.preventDefault();
        document.querySelectorAll('#studentForm > section').forEach(function (section) {
          section.classList.toggle('d-none', section !== target);
        });
        const header = document.querySelector('.student-top-summary');
        const stickyTop = header ? (parseFloat(window.getComputedStyle(header).top) || 0) : 0;
        const headerHeight = (header?.offsetHeight || 0) + stickyTop;
        const heading = target.querySelector('.section-heading') || target;
        window.scrollTo({ top: Math.max(0, heading.getBoundingClientRect().top + window.scrollY - headerHeight - 12), behavior: 'smooth' });
        history.replaceState(null, '', link.getAttribute('href'));
      }
      document.querySelectorAll('.student-nav a, .student-top-nav a').forEach(function (item) { item.classList.remove('active'); });
      link.classList.add('active');
    });
  });
  const form = document.getElementById('studentForm'); if (!form) return;
  const sections = [...form.querySelectorAll(':scope > section')];
  const hashTarget = window.location.hash ? document.querySelector(window.location.hash) : null;
  const initialSection = hashTarget || document.getElementById('peribadi');
  sections.forEach(section => section.classList.toggle('d-none', section !== initialSection));
  const editable = [...form.querySelectorAll('input:not([readonly]), select:not([readonly]):not([data-auto-warga])')];
  const initial = new Map(editable.map(el => [el.name, el.value]));
  const edit = document.getElementById('editBtn'), saveBar = document.getElementById('saveBar'), cancel = document.getElementById('cancelBtn');
  const editSections = ['peribadi', 'maklumat-penjaga'];
  const updateEditVisibility = () => {
    const activeSection = sections.find(section => !section.classList.contains('d-none'));
    const canEdit = activeSection && editSections.includes(activeSection.id);
    if (canEdit) activeSection.querySelector('.section-heading')?.appendChild(edit);
    edit.classList.toggle('d-none', !canEdit);
  };
  document.querySelectorAll('.student-nav a, .student-top-nav a').forEach(link => link.addEventListener('click', updateEditVisibility));
  updateEditVisibility();
  const citizenship = document.getElementById('kewarganegaraan');
  const wargaSelect = document.getElementById('wargaSelect');
  const wargaCode = document.getElementById('kdwarga');
  const syncCitizenshipStatus = () => {
    if (!citizenship || !wargaSelect || !wargaCode) return;
    const option = citizenship.options[citizenship.selectedIndex];
    const isMalaysia = /malaysia/i.test(option?.dataset.label || option?.text || '');
    const code = citizenship.value === '' ? '' : (isMalaysia ? 'W' : 'B');
    wargaSelect.value = code;
    wargaCode.value = code;
  };
  if (citizenship) citizenship.addEventListener('change', syncCitizenshipStatus);
  syncCitizenshipStatus();
  editable.forEach(el => el.disabled = true);
  edit.addEventListener('click', () => { editable.forEach(el => el.disabled = false); saveBar.classList.remove('d-none'); edit.classList.add('d-none'); });
  cancel.addEventListener('click', () => { editable.forEach(el => { el.value = initial.get(el.name) ?? ''; el.disabled = true; }); syncCitizenshipStatus(); saveBar.classList.add('d-none'); edit.classList.remove('d-none'); });
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const payload = Object.fromEntries(new FormData(form));
    const submit = form.querySelector('[type="submit"]');
    const submitHtml = submit.innerHTML;
    const loaderToken = window.AppLoader
      ? window.AppLoader.show('Menyimpan maklumat pelajar...')
      : null;
    submit.disabled = true;
    submit.innerHTML = '<i class="ri-loader-4-line me-1"></i>Menyimpan...';

    try {
      const res = await fetch(window.STUDENT_UPDATE_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': window.STUDENT_CSRF,
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(payload)
      });
      const responseText = await res.text();
      let data;
      try {
        data = JSON.parse(responseText);
      } catch (_) {
        throw new Error('Respons simpanan tidak sah. Sila muat semula halaman dan cuba lagi.');
      }
      if (!res.ok || data.success === false || data.error) {
        throw new Error(data.message || 'Simpan gagal.');
      }

      Object.entries(payload).forEach(([key, value]) => initial.set(key, String(value)));
      editable.forEach(el => { el.disabled = true; });
      saveBar.classList.add('d-none');
      edit.classList.remove('d-none');

      const auditMessage = data.audit_logged === false
        ? 'Maklumat disimpan, tetapi audit perlu disemak oleh pentadbir.'
        : data.message;
      if (window.Swal) {
        Swal.fire({
          icon: data.audit_logged === false ? 'warning' : 'success',
          title: data.audit_logged === false ? 'Disimpan dengan amaran' : 'Berjaya',
          text: auditMessage,
          timer: 1200,
          showConfirmButton: false
        });
      } else {
        alert(auditMessage);
      }
    } catch (err) {
      if (window.Swal) Swal.fire({ icon: 'error', title: 'Tidak berjaya', text: err.message });
      else alert(err.message);
    } finally {
      if (window.AppLoader) window.AppLoader.hide(loaderToken);
      submit.disabled = false;
      submit.innerHTML = submitHtml;
    }
  });
});
