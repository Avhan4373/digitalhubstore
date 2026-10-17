/**
 * Interaksi storefront & admin.
 *
 * Ikon memakai pustaka `lucide` dengan atribut `data-lucide="nama-ikon"` —
 * sama seperti situs asal — lalu ditukar jadi SVG inline oleh `createIcons()`.
 * Semua handler dipasang lewat atribut data-* (tanpa HTML inline) supaya CSP
 * produksi tetap `script-src 'self'`.
 */
import {
    createIcons,
    AlertCircle,
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    Bell,
    BellRing,
    Bot,
    Camera,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock,
    Copy,
    Download,
    ExternalLink,
    FileText,
    Filter,
    Flame,
    Gift,
    Globe,
    Grid,
    HelpCircle,
    Home,
    Info,
    Layout,
    LayoutDashboard,
    LayoutGrid,
    Loader2,
    Lock,
    LockOpen,
    LogIn,
    Menu,
    MessageCircle,
    MessageSquareMore,
    MessageSquarePlus,
    Minus,
    Monitor,
    Package,
    Phone,
    PlaySquare,
    Plus,
    Printer,
    QrCode,
    Receipt,
    RefreshCw,
    ScrollText,
    Search,
    SearchX,
    Send,
    Settings,
    ShieldAlert,
    ShieldCheck,
    ShoppingBag,
    ShoppingCart,
    Sparkles,
    Star,
    Store,
    Tag,
    Trash2,
    TriangleAlert,
    Upload,
    User,
    Users,
    Video,
    Wallet,
    X,
    Zap,
} from 'lucide';

const ICONS = {
    AlertCircle,
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    Bell,
    BellRing,
    Bot,
    Camera,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock,
    Copy,
    Download,
    ExternalLink,
    FileText,
    Filter,
    Flame,
    Gift,
    Globe,
    Grid,
    HelpCircle,
    Home,
    Info,
    Layout,
    LayoutDashboard,
    LayoutGrid,
    Loader2,
    Lock,
    LockOpen,
    LogIn,
    Menu,
    MessageCircle,
    MessageSquareMore,
    MessageSquarePlus,
    Minus,
    Monitor,
    Package,
    Phone,
    PlaySquare,
    Plus,
    Printer,
    QrCode,
    Receipt,
    RefreshCw,
    ScrollText,
    Search,
    SearchX,
    Send,
    Settings,
    ShieldAlert,
    ShieldCheck,
    ShoppingBag,
    ShoppingCart,
    Sparkles,
    Star,
    Store,
    Tag,
    Trash2,
    TriangleAlert,
    Upload,
    User,
    Users,
    Video,
    Wallet,
    X,
    Zap,
};

/** Render ulang semua elemen `[data-lucide]` menjadi SVG. */
export function renderIcons(root = document) {
    createIcons({ icons: ICONS, root });
}

const rupiah = (value) =>
    'Rp ' + new Intl.NumberFormat('id-ID').format(Math.max(0, Math.round(Number(value) || 0)));

/* ------------------------------------------------------------------ toast */
function toast(message, type = 'success') {
    const host = document.getElementById('toast-host');
    if (!host) return;

    const el = document.createElement('div');
    el.className =
        'pointer-events-auto rounded-xl px-4 py-3 text-sm font-medium shadow-lg ring-1 transition ' +
        (type === 'error'
            ? 'bg-rose-50 text-rose-800 ring-rose-200'
            : 'bg-emerald-50 text-emerald-800 ring-emerald-200');
    el.textContent = message;
    host.appendChild(el);

    setTimeout(() => {
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 300);
    }, 4000);
}

/**
 * Nav mobile admin: drawer slide dari kiri (tombol hamburger di header +
 * item "Lainnya" di bottom nav), backdrop menutup di luar, Escape, pilih
 * tautan, dan bypass safe area bottom nav.
 */
function initAdminNav() {
    const drawer = document.querySelector('[data-admin-drawer]');
    const backdrop = document.querySelector('[data-admin-drawer-backdrop]');
    if (!drawer) return;

    let terbuka = false;

    const atur = (buka) => {
        terbuka = buka;
        drawer.classList.toggle('-translate-x-full', !buka);
        drawer.classList.toggle('translate-x-0', buka);
        if (backdrop) backdrop.classList.toggle('hidden', !buka);
        document.body.classList.toggle('overflow-hidden', buka);
        document.querySelectorAll('[data-admin-nav-toggle]').forEach((tombol) => {
            tombol.setAttribute('aria-expanded', String(buka));
        });
    };

    document.querySelectorAll('[data-admin-nav-toggle]').forEach((tombol) => {
        tombol.addEventListener('click', () => atur(!terbuka));
    });

    if (backdrop) {
        backdrop.addEventListener('click', () => atur(false));
    }

    drawer.addEventListener('click', (event) => {
        if (event.target.closest('a')) atur(false);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && terbuka) atur(false);
    });
}

/* ---------------------------------------------------------------- panel admin */
/**
 * Oper konfirmasi dari atribut data-confirm (pengganti onsubmit="return
 * confirm(...)" yang dinonaktifkan CSP `script-src 'self'`).
 */
/**
 * Status pesanan pembeli tanpa refresh manual ("realtime" sederhana lewat
 * polling `orders.status` ~10 dtk): pembeli tinggal diam di halaman pesanan;
 * kalau admin mengonfirmasi bayar / mengirim isi akun / unlock kedaluwarsa,
 * halaman dimuat ulang dengan pemberitahuan singkat.
 */
function initOrderLive() {
    const el = document.querySelector('[data-order-live]');
    if (!el) return;

    const url = el.dataset.orderLive;
    let kunci = el.dataset.liveKey;
    let memuat = false;

    const periksa = async () => {
        if (memuat || document.hidden) return;

        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;

            const data = await res.json();
            const kini = [
                data.payment_status,
                data.order_status,
                data.punya_isi ? 1 : 0,
                data.terbuka ? 1 : 0,
            ].join('|');

            if (kini !== kunci) {
                memuat = true;
                toast('Status pesanan diperbarui — memuat ulang halaman…');
                setTimeout(() => window.location.reload(), 1200);
            }
        } catch {
            /* koneksi terputus — diulang pada putaran berikutnya */
        }
    };

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) periksa();
    });

    setInterval(periksa, 10000);
}

function initConfirm() {
    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) return;

        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
}

/**
 * Deteksi pesanan masuk untuk panel admin ("realtime" lewat polling ringan,
 * ±8 detik — tanpa websocket supaya server PHP biasa tetap sanggup):
 *
 * - bell di header menampilkan jumlah pesanan menunggu ditindak dan membuka
 *   panel 10 pesanan terbaru (dirender lewat textContent, bukan innerHTML);
 * - angka pada pill "Belum bayar" / "Perlu kirim" ikut menyegarkan;
 * - pesanan yang belum pernah terlihat di tab ini memunculkan toast + bunyi
 *   pendek (Web Audio, tanpa berkas eksternal; ditolak autoplay pun diam).
 */
function initAdminLive() {
    const root = document.querySelector('[data-admin-live]');
    if (!root) return;

    const url = root.dataset.liveUrl;
    const panel = root.querySelector('[data-live-panel]');
    const daftar = root.querySelector('[data-live-list]');
    const badge = root.querySelector('[data-live-badge]');
    const pillBayar = document.querySelector('[data-live-unpaid]');
    const pillKirim = document.querySelector('[data-live-awaiting]');

    let dasar = null;          // momen terbaru saat panel baru dibuka
    const terlihat = new Set();
    let suara = null;

    const bunyikan = () => {
        try {
            const Konteks = window.AudioContext || window.webkitAudioContext;
            if (!Konteks) return;
            suara ||= new Konteks();
            if (suara.state === 'suspended') suara.resume();

            const osc = suara.createOscillator();
            const gain = suara.createGain();
            osc.connect(gain);
            gain.connect(suara.destination);
            osc.type = 'sine';
            gain.gain.value = 0.06;
            osc.frequency.setValueAtTime(880, suara.currentTime);
            osc.frequency.setValueAtTime(660, suara.currentTime + 0.14);
            osc.start(suara.currentTime);
            osc.stop(suara.currentTime + 0.28);
        } catch {
            /* autoplay ditolak atau Web Audio kosong — diamkan saja */
        }
    };

    const permakItem = (baris) => {
        const tautan = document.createElement('a');
        tautan.href = baris.url;
        tautan.className = 'flex items-center gap-3 rounded-xl px-3 py-2.5 transition hover:bg-slate-50';

        const kotak = document.createElement('span');
        kotak.className = `grid h-9 w-9 shrink-0 place-items-center rounded-xl ${
            baris.lunas ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600'
        }`;
        const ikon = document.createElement('i');
        ikon.setAttribute('data-lucide', baris.lunas ? 'package' : 'receipt');
        ikon.className = 'h-4 w-4';
        kotak.append(ikon);

        const tengah = document.createElement('span');
        tengah.className = 'min-w-0 flex-1';
        const invoice = document.createElement('span');
        invoice.className = 'block truncate font-mono text-xs font-bold text-slate-800';
        invoice.textContent = baris.invoice;
        const pembeli = document.createElement('span');
        pembeli.className = 'block truncate text-[11px] text-slate-500';
        pembeli.textContent = `${baris.buyer} · ${baris.waktu}`;
        tengah.append(invoice, pembeli);

        const total = document.createElement('span');
        total.className = 'shrink-0 text-xs font-black text-slate-900';
        total.textContent = rupiah(baris.total);

        tautan.append(kotak, tengah, total);
        return tautan;
    };

    const segarkanPanel = (data) => {
        if (!daftar) return;
        daftar.replaceChildren();

        if (!data.terbaru.length) {
            const kosong = document.createElement('p');
            kosong.className = 'px-3 py-6 text-center text-xs text-slate-400';
            kosong.textContent = 'Belum ada pesanan masuk.';
            daftar.append(kosong);
            return;
        }

        data.terbaru.forEach((baris) => daftar.append(permakItem(baris)));
        renderIcons(daftar);
    };

    let dataTerakhir = null;

    const angka = (data) => {
        dataTerakhir = data;
        const menunggu = (data.belum_bayar || 0) + (data.perlu_kirim || 0);
        if (badge) {
            badge.textContent = String(menunggu);
            badge.classList.toggle('hidden', menunggu === 0);
        }
        if (pillBayar) pillBayar.textContent = String(data.belum_bayar || 0);
        if (pillKirim) pillKirim.textContent = String(data.perlu_kirim || 0);
    };

    const simak = async () => {
        if (!document.hidden) {
            try {
                const tangkap = await fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const data = await tangkap.json();

                angka(data);

                if (dasar === null) {
                    // percobaan pertama: tandai semua sebagai pesanan yang sudah dikenal
                    data.terbaru.forEach((baris) => terlihat.add(baris.invoice));
                    dasar = data.terbaru[0]?.momen ?? 0;
                } else if (data.terbaru.length) {
                    const maks = data.terbaru[0].momen;

                    data.terbaru
                        .filter((baris) => !terlihat.has(baris.invoice) && baris.momen > dasar)
                        .forEach((baris) => {
                            terlihat.add(baris.invoice);
                            toast(`Pesanan masuk: ${baris.invoice} — ${rupiah(baris.total)} (${baris.buyer})`);
                            bunyikan();
                        });

                    if (maks > dasar) dasar = maks;
                }

                segarkanPanel(data);
            } catch {
                /* offline / server sibuk — coba lagi pada putaran berikut */
            }
        }

        setTimeout(simak, 8000);
    };

    document.querySelectorAll('[data-live-toggle]').forEach((tombol) => {
        tombol.addEventListener('click', () => {
            const terbuka = panel.classList.toggle('hidden') === false;
            if (terbuka && dataTerakhir) segarkanPanel(dataTerakhir);
        });
    });

    // tutup panel kalau klik di luar bell
    document.addEventListener('click', (event) => {
        if (!panel || panel.classList.contains('hidden')) return;
        if (!root.contains(event.target)) panel.classList.add('hidden');
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && panel) panel.classList.add('hidden');
    });

    simak();
}

/* ------------------------------------------------------------ copy helper */
function copyText(text) {
    const done = () => toast('Disalin ke clipboard.');
    if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(() => fallback(text, done));
    } else {
        fallback(text, done);
    }
}

function fallback(text, done) {
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    try {
        document.execCommand('copy');
        done();
    } catch (e) {
        toast('Gagal menyalin, silakan salin manual.', 'error');
    }
    area.remove();
}

/* --------------------------------------------------------- pemilih varian */
/**
 * Halaman detail produk: chip varian + stepper jumlah + bar aksi bawah.
 * Harga tetap dihitung ulang di server saat checkout.
 */
function initVariantPicker(scope) {
    const form = scope.querySelector('[data-variant-form]');
    if (!form) return;

    // Root bisa lebih luas dari <form> karena output harga ada di blok
    // identitas dan bar aksi (sticky) berada di luar form — kontrol form-nya
    // dihubungkan lewat atribut form="..." (lihat product.blade.php).
    const root = form.closest('[data-picker-root]') || form;
    const inputs = root.querySelectorAll('input[name="variant_id"]');
    const priceOut = root.querySelector('[data-price-output]');
    const compareOut = root.querySelector('[data-compare-output]');
    const stockOut = root.querySelector('[data-stock-output]');
    const totalOut = root.querySelector('[data-total-output]');
    const qtyInput = root.querySelector('[data-qty]');
    const qtyOut = root.querySelector('[data-qty-output]');
    const metaOut = root.querySelector('[data-variant-meta]');
    const buttons = root.querySelectorAll('[data-submit]');
    const maxQty = Number(form.dataset.maxQty || 20);

    const sync = () => {
        const picked = form.querySelector('input[name="variant_id"]:checked');
        if (!picked) {
            buttons.forEach((b) => (b.disabled = true));
            return;
        }

        const price = Number(picked.dataset.price || 0);
        const compare = Number(picked.dataset.compare || 0);
        const stock = picked.dataset.stock;
        const limit = Number(picked.dataset.limit || maxQty);
        const qty = Math.max(1, Number(qtyInput?.value || 1));

        if (priceOut) priceOut.textContent = rupiah(price);
        if (compareOut) {
            compareOut.textContent = compare > price ? rupiah(compare) : '';
            compareOut.classList.toggle('hidden', !(compare > price));
        }
        if (stockOut) {
            stockOut.textContent = stock === '' || stock === undefined ? 'Stok tersedia' : `Tersedia ${stock} Akun Instan`;
            stockOut.dataset.state = Number(stock) > 0 ? 'ready' : 'empty';
        }
        if (totalOut) totalOut.textContent = rupiah(price * qty);
        if (qtyInput) {
            qtyInput.max = Math.min(maxQty, limit);
            if (Number(qtyInput.value) > Number(qtyInput.max)) qtyInput.value = qtyInput.max;
        }
        if (qtyOut) qtyOut.textContent = qtyInput?.value ?? '1';

        if (metaOut) {
            const parts = [];
            if (picked.dataset.delivery) parts.push(picked.dataset.delivery);
            if (picked.dataset.required) parts.push('Wajib diisi saat checkout: ' + picked.dataset.required);
            metaOut.textContent = parts.join(' · ');
            metaOut.classList.toggle('hidden', parts.length === 0);
        }

        const purchasable = picked.dataset.purchasable === '1';
        buttons.forEach((b) => (b.disabled = !purchasable));
    };

    inputs.forEach((input) => input.addEventListener('change', sync));

    root.querySelector('[data-qty-dec]')?.addEventListener('click', () => {
        if (qtyInput) qtyInput.value = Math.max(1, Number(qtyInput.value) - 1);
        sync();
    });

    root.querySelector('[data-qty-inc]')?.addEventListener('click', () => {
        if (qtyInput) {
            const max = Number(qtyInput.max || maxQty);
            qtyInput.value = Math.min(max, Number(qtyInput.value) + 1);
        }
        sync();
    });

    qtyInput?.addEventListener('change', () => {
        qtyInput.value = Math.max(1, Math.min(Number(qtyInput.max || maxQty), Number(qtyInput.value || 1)));
        sync();
    });

    sync();
}

/* ----------------------------------------------------------- slider produk */
function initSliders(root = document) {
    root.querySelectorAll('[data-slider]').forEach((slider) => {
        const dots = root.querySelectorAll(`[data-slider-dots="${slider.dataset.slider}"] [data-dot]`);
        if (!dots.length) return;

        const activate = (index) => {
            dots.forEach((dot, i) => {
                dot.classList.toggle('w-4', i === index);
                dot.classList.toggle('bg-white', i === index);
                dot.classList.toggle('bg-white/50', i !== index);
            });
        };

        slider.addEventListener('scroll', () => {
            const index = Math.round(slider.scrollLeft / slider.clientWidth);
            activate(Math.min(dots.length - 1, Math.max(0, index)));
        });

        dots.forEach((dot, i) =>
            dot.addEventListener('click', () => slider.scrollTo({ left: i * slider.clientWidth, behavior: 'smooth' })),
        );
    });
}

/* ----------------------------------------------------------- filter ulasan */
function initReviewFilters(root = document) {
    const buttons = root.querySelectorAll('[data-review-filter]');
    if (!buttons.length) return;

    const items = root.querySelectorAll('[data-review-item]');
    const empty = root.querySelector('[data-review-empty]');

    const keywords = {
        instant: ['cepat', 'instan', 'langsung', 'seketika'],
        service: ['garansi', 'aman', 'amanah', 'respon', 'fast respon', 'pelayanan'],
    };

    const matches = (item, filter) => {
        if (filter === 'all') return true;
        if (filter === 'star5') return Number(item.dataset.rating) === 5;
        const text = (item.dataset.comment || '').toLowerCase();
        return (keywords[filter] || []).some((word) => text.includes(word));
    };

    const apply = (filter) => {
        let shown = 0;
        items.forEach((item) => {
            const ok = matches(item, filter);
            item.classList.toggle('hidden', !ok);
            if (ok) shown += 1;
        });
        if (empty) empty.classList.toggle('hidden', shown > 0);
        buttons.forEach((btn) => {
            const active = btn.dataset.reviewFilter === filter;
            btn.classList.toggle('bg-rose-50', active);
            btn.classList.toggle('text-brand-500', active);
            btn.classList.toggle('border-rose-200', active);
            btn.classList.toggle('font-bold', active);
            btn.classList.toggle('bg-slate-100', !active);
            btn.classList.toggle('text-slate-600', !active);
            btn.classList.toggle('font-medium', !active);
        });
    };

    buttons.forEach((btn) => btn.addEventListener('click', () => apply(btn.dataset.reviewFilter)));
}

/* --------------------------------------------------------- social proof */
/**
 * Kartu kecil di kiri bawah yang menampilkan pesanan terbaru secara bergiliran.
 * Datanya berasal dari pesanan nyata (nama sudah disamarkan di server).
 */
function initSocialProof() {
    const box = document.getElementById('social-proof-toast');
    if (!box) return;

    let items = [];

    try {
        items = JSON.parse(box.dataset.items || '[]');
    } catch (e) {
        return;
    }

    if (!items.length) return;

    const userOut = box.querySelector('[data-proof-user]');
    const productOut = box.querySelector('[data-proof-product]');
    const timeOut = box.querySelector('[data-proof-time]');
    let index = 0;

    const show = () => {
        const item = items[index % items.length];
        if (userOut) userOut.textContent = item.name;
        if (productOut) productOut.textContent = item.product;
        if (timeOut) timeOut.textContent = `${item.time} • Terverifikasi Otomatis`;

        box.classList.remove('opacity-0', 'translate-y-6');
        box.classList.add('opacity-100', 'translate-y-0');

        setTimeout(() => {
            box.classList.add('opacity-0', 'translate-y-6');
            box.classList.remove('opacity-100', 'translate-y-0');
            index += 1;
            setTimeout(show, 6000);
        }, 6000);
    };

    setTimeout(show, 4000);
}

/* --------------------------------------------------------- bantuan cepat */
/**
 * Bubble "Butuh bantuan? Chat kami 👋" di atas tombol chat mengambang.
 * Muncul 12 detik setelah halaman dibuka, lalu tampil-hilang berkala.
 */
function initQuickHelp() {
    const bubble = document.getElementById('quick-help-bubble');
    if (!bubble) return;

    const loop = () => {
        bubble.classList.remove('hidden');

        setTimeout(() => {
            bubble.classList.add('hidden');
            setTimeout(loop, 45000);
        }, 7000);
    };

    setTimeout(loop, 12000);
}

/* ------------------------------------------------------------ popup storefront */
/**
 * Buka/tutup popup storefront — popup "Cek Transaksi" (`#cek-modal`) dan
 * lembar E-Invoice (`.cek-sheet-modal`, satu lembar per kartu pesanan),
 * dua lapis seperti situs asal.
 *
 * `display` di-set lewat inline style supaya tidak bergantung pada urutan
 * utility Tailwind (`hidden` vs `flex`). Elemen `[data-modal-open]` yang berupa
 * <a> tetap berguna tanpa JS: default action hanya dibatalkan kalau popup
 * tujuan memang ada di halaman.
 */
function initModals() {
    const modals = [...document.querySelectorAll('[data-auto-open]')];
    if (modals.length === 0) return;

    const segarkanKunciScroll = () => {
        const ada = modals.some((m) => m.style.display === 'flex');
        document.documentElement.classList.toggle('overflow-hidden', ada);
        document.body.classList.toggle('overflow-hidden', ada);
    };

    const buka = (id) => {
        const modal = id ? document.getElementById(id) : null;
        if (!modal) return false;

        modal.style.display = 'flex';
        segarkanKunciScroll();

        return true;
    };

    const tutup = (id) => {
        modals.filter((m) => !id || m.id === id).forEach((m) => {
            m.style.display = 'none';
        });
        segarkanKunciScroll();
    };

    document.querySelectorAll('[data-modal-open]').forEach((el) => {
        el.addEventListener('click', (event) => {
            // <a href> tetap jalan kalau popup-nya tidak ada di halaman ini.
            if (buka(el.dataset.modalOpen)) event.preventDefault();
        });

        if (el.tagName === 'DIV') {
            el.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    buka(el.dataset.modalOpen);
                }
            });
        }
    });

    document.querySelectorAll('[data-modal-close]').forEach((el) => {
        el.addEventListener('click', () => tutup(el.dataset.modalClose));
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') tutup();
    });

    // popup yang harus terbuka sendiri: hasil pencarian invoice / pesan error
    modals.forEach((m) => {
        if (m.dataset.autoOpen === '1') m.style.display = 'flex';
    });
    segarkanKunciScroll();

    // tab status di popup (Semua / Menunggu / Selesai) menyaring kartu
    document.querySelectorAll('[data-cek-tab]').forEach((tab) => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('[data-cek-tab]').forEach((lain) => {
                const aktif = lain === tab;
                lain.classList.toggle('border-[#f5125e]', aktif);
                lain.classList.toggle('text-[#f5125e]', aktif);
                lain.classList.toggle('border-transparent', !aktif);
            });

            document.querySelectorAll('[data-cek-card]').forEach((kartu) => {
                const tampil = tab.dataset.cekTab === 'all'
                    || kartu.dataset.cekStatus === tab.dataset.cekTab;
                kartu.style.display = tampil ? '' : 'none';
            });
        });
    });

    // "Unduh PDF" di lembar invoice: cetak lembarnya saja, bukan seluruh halaman
    document.querySelectorAll('[data-print-sheet]').forEach((btn) => {
        btn.addEventListener('click', () => {
            document.body.dataset.printScope = 'sheet';

            const bersihkan = () => {
                delete document.body.dataset.printScope;
                window.removeEventListener('afterprint', bersihkan);
            };

            window.addEventListener('afterprint', bersihkan);
            window.print();
            setTimeout(bersihkan, 2000);
        });
    });
}

/* --------------------------------------------------------------- inisialisasi */
document.addEventListener('DOMContentLoaded', () => {
    renderIcons();

    document.querySelectorAll('[data-variant-picker]').forEach(initVariantPicker);
    initSliders();
    initReviewFilters();
    initModals();
    initConfirm();
    initAdminLive();
    initOrderLive();
    initAdminNav();

    document.querySelectorAll('[data-copy]').forEach((btn) => {
        btn.addEventListener('click', () => copyText(btn.dataset.copy));
    });

    // tombol info singkat (menggantikan showToast() situs asal)
    document.querySelectorAll('[data-toast]').forEach((el) => {
        el.addEventListener('click', () => toast(el.dataset.toast, el.dataset.toastType || 'success'));
    });

    // cetak / simpan PDF (pengganti onclick inline supaya CSP tetap ketat)
    document.querySelectorAll('[data-print]').forEach((btn) => {
        btn.addEventListener('click', () => window.print());
    });

    // notifikasi "pembeli lain baru saja order" (dibuat dari pesanan nyata,
    // nama pembeli selalu disamarkan). Bisa dimatikan dari pengaturan admin.
    initSocialProof();

    // bubble "Butuh bantuan? Chat kami" di atas tombol chat (gaya situs asal):
    // muncul sebentar, hilang, lalu muncul lagi berkala.
    initQuickHelp();

    // select yang langsung mengirim form-nya (urutkan, kategori)
    document.querySelectorAll('[data-auto-submit]').forEach((select) => {
        select.addEventListener('change', () => select.closest('form')?.submit());
    });

    // stepper jumlah generik (dipakai di keranjang)
    document.querySelectorAll('[data-step]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const row = btn.closest('[data-step-row]') || btn.parentElement;
            const input = row?.querySelector('[data-qty-input]');
            if (!input) return;

            const min = Number(input.min || 0);
            const max = Number(input.max || 20);
            const next = Math.min(max, Math.max(min, Number(input.value || 0) + Number(btn.dataset.step)));
            input.value = next;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    // sinkronkan harga di halaman keranjang
    const cart = document.querySelector('[data-cart-form]');
    if (cart) {
        cart.addEventListener('change', (event) => {
            if (event.target.matches('[data-qty-input]')) {
                const row = event.target.closest('[data-cart-row]');
                const price = Number(row?.dataset.price || 0);
                const out = row?.querySelector('[data-line-total]');
                if (out) out.textContent = rupiah(price * Number(event.target.value || 0));

                const total = [...cart.querySelectorAll('[data-cart-row]')].reduce((sum, r) => {
                    const qty = Number(r.querySelector('[data-qty-input]')?.value || 0);
                    return sum + qty * Number(r.dataset.price || 0);
                }, 0);
                const grand = cart.querySelector('[data-cart-total]');
                if (grand) grand.textContent = rupiah(total);
            }
        });
    }

    // polling status pembayaran (hanya untuk halaman pembayaran manual)
    const poll = document.querySelector('[data-poll-status]');
    if (poll) {
        const url = poll.dataset.pollStatus;
        const timer = setInterval(async () => {
            try {
                const res = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!res.ok) return;
                const data = await res.json();
                if (data.is_paid || data.payment_status === 'expired') {
                    clearInterval(timer);
                    toast(data.is_paid
                        ? 'Pembayaran diterima — memuat halaman pesanan…'
                        : 'Pesanan kedaluwarsa — memuat ulang halaman…');
                    setTimeout(() => window.location.reload(), 1200);
                }
            } catch (e) {
                /* diamkan: koneksi terputus bukan error kritis */
            }
        }, Number(poll.dataset.pollInterval || 15000));
    }

    // hitung mundur batas waktu bayar
    document.querySelectorAll('[data-countdown]').forEach((el) => {
        const deadline = new Date(el.dataset.countdown).getTime();
        const out = el.querySelector('[data-countdown-text]') || el;

        const tick = () => {
            const left = deadline - Date.now();
            if (left <= 0) {
                out.textContent = '00:00';
                return;
            }
            const total = Math.floor(left / 1000);
            const hours = String(Math.floor(total / 3600)).padStart(2, '0');
            const mins = String(Math.floor((total % 3600) / 60)).padStart(2, '0');
            const secs = String(total % 60).padStart(2, '0');
            out.textContent = Number(hours) > 0 ? `${hours}:${mins}:${secs}` : `${mins}:${secs}`;
        };

        tick();
        setInterval(tick, 1000);
    });
});

/* ekspor agar bisa dipakai inline handler di admin */
window.toast = toast;
window.copyText = copyText;
window.renderIcons = renderIcons;

/**
 * Bersihkan sisa aplikasi lama (Aero) dari browser pengunjung.
 *
 * Origin ini sebelumnya menyajikan aplikasi lain yang mendaftarkan service
 * worker `/sw.js` dan menyimpan app shell di CacheStorage. SW itu menjalankan
 * `location.replace("login.html")`, sehingga pengunjung lama terlempar ke
 * halaman login padahal sedang membuka storefront.
 *
 * Dua hal harus dibereskan, dan keduanya tidak bisa diperbaiki dari server:
 *
 *  1. Registrasi service worker lama — hanya bisa dilepas dari sisi klien.
 *  2. Dokumen Aero yang masih di HTTP cache. Saat Aero hidup, nginx menyajikan
 *     `index.html` sebagai berkas statis tanpa `Cache-Control`, jadi browser
 *     memakai heuristic caching dan bisa menyajikan HTML lama berhari-hari.
 *     Dokumen itu tetap memuat SPA Aero walau service worker-nya sudah hilang.
 *
 * Karena itu, kalau kita mendarat di `/login` akibat dilempar aplikasi lama,
 * kita pulangkan pengunjung ke toko lewat URL ber-cache-buster (`?_r=`) supaya
 * pasti diambil dari jaringan. Sekali per tab agar tidak berputar-putar.
 */
function bersihkanSisaAplikasiLama() {
    const diHalamanLogin = window.location.pathname === '/login';

    // Referer `/` atau `login.html` berarti kita sampai di /login bukan karena
    // sengaja membuka panel admin, melainkan karena dilempar aplikasi lama.
    const dilemparAplikasiLama = (() => {
        if (!diHalamanLogin || !document.referrer) return false;

        try {
            const asal = new URL(document.referrer);
            if (asal.origin !== window.location.origin) return false;

            return asal.pathname === '/' || asal.pathname === '/login.html';
        } catch (e) {
            return false;
        }
    })();

    const pulangkanKeToko = () => {
        if (!diHalamanLogin) return;

        try {
            if (sessionStorage.getItem('sisa_lama_dipulihkan')) return;
            sessionStorage.setItem('sisa_lama_dipulihkan', '1');
        } catch (e) {
            return; // storage diblokir — jangan risiko loop
        }

        window.location.replace('/?_r=' + Date.now());
    };

    if (!('serviceWorker' in navigator)) {
        if (dilemparAplikasiLama) pulangkanKeToko();
        return;
    }

    navigator.serviceWorker.getRegistrations().then((regs) => {
        // Toko tidak punya service worker sama sekali, jadi registrasi apa pun
        // di origin ini pasti sisa aplikasi lama.
        if (regs.length) {
            regs.forEach((reg) => reg.unregister().catch(() => {}));

            if (window.caches && caches.keys) {
                caches.keys().then((keys) => {
                    keys.forEach((key) => caches.delete(key).catch(() => {}));
                }).catch(() => {});
            }
        }

        if (dilemparAplikasiLama) pulangkanKeToko();
    }).catch(() => {
        if (dilemparAplikasiLama) pulangkanKeToko();
    });
}

bersihkanSisaAplikasiLama();
