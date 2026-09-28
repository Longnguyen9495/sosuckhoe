/**
 * Bộ icon nét Lucide (https://lucide.dev, giấy phép ISC) — thay cho emoji để giao diện đồng nhất, hiện đại.
 * Chỉ nhập các icon dùng đến; Vite loại bỏ phần còn lại khi build.
 */
import {
    Activity, AlarmClock, Copy, History, Link, QrCode, Share2, X, Apple, Ban, Cake, CalendarDays, Camera, Check, ChevronLeft, ChevronRight, CircleCheck, Clock,
    Download, Droplet, Droplets, Dumbbell, Eye, FileText, FlaskConical, Folder, Footprints, HeartPulse, Hospital, House,
    Image, Info, KeyRound, Leaf, Lock, MessageCircle, Moon, NotebookPen, Package, PartyPopper, Pill, Pin, Play, Plus,
    Salad, Settings, ShieldCheck, ShoppingCart, Smartphone, Sparkles, Stethoscope, Sun, Sunrise, Syringe, TrendingUp,
    TriangleAlert, UserRound, Utensils, WifiOff, ScanLine, Type, ChartLine, FileDown, Bell, Maximize,
} from 'lucide';

const SET = {
    activity: Activity, alarm: AlarmClock, copy: Copy, history: History, link: Link, qr: QrCode, share: Share2, close: X, apple: Apple, ban: Ban, cake: Cake, calendar: CalendarDays, camera: Camera,
    check: Check, 'chevron-left': ChevronLeft, 'chevron-right': ChevronRight, 'check-circle': CircleCheck, clock: Clock,
    download: Download, droplet: Droplet, droplets: Droplets, dumbbell: Dumbbell, eye: Eye, file: FileText, flask: FlaskConical,
    folder: Folder, footprints: Footprints, heart: HeartPulse, hospital: Hospital, house: House, image: Image, info: Info,
    key: KeyRound, leaf: Leaf, lock: Lock, chat: MessageCircle, moon: Moon, note: NotebookPen, package: Package,
    party: PartyPopper, pill: Pill, pin: Pin, play: Play, plus: Plus, salad: Salad, settings: Settings, shield: ShieldCheck,
    cart: ShoppingCart, phone: Smartphone, sparkles: Sparkles, stethoscope: Stethoscope, sun: Sun, sunrise: Sunrise,
    syringe: Syringe, trend: TrendingUp, warn: TriangleAlert, user: UserRound, utensils: Utensils,
    'wifi-off': WifiOff, scan: ScanLine, type: Type, chart: ChartLine, 'file-down': FileDown, bell: Bell, maximize: Maximize,
};

const attrs = (o) => Object.entries(o).map(([k, v]) => `${k}="${String(v).replace(/"/g, '&quot;')}"`).join(' ');

/**
 * SVG của một icon. `size` px; `cls` thêm class; `fill` để tô đặc (VD nút play).
 * Mặc định aria-hidden — chữ đi kèm (hoặc aria-label của nút) mới là nội dung đọc cho người dùng.
 */
export function icon(name, { size = 20, cls = '', fill = 'none', stroke = 2 } = {}) {
    const node = SET[name];
    if (!node) return '';
    const inner = node.map(([tag, a]) => `<${tag} ${attrs(a)}/>`).join('');
    return `<svg class="ic ${cls}" xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 24 24" fill="${fill}" stroke="currentColor" stroke-width="${stroke}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${inner}</svg>`;
}
