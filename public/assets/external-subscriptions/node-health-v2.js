// Loaded only by the verified stock user theme. All values come from its existing user API response.
const labels = {
  'zh-CN': ['在线', '离线', '待复查', '未知', '最近检测', '尚无检测记录', '自建节点显示最近五分钟心跳；第三方节点可查看最近检测时间。'],
  'zh-TW': ['上線', '離線', '待複查', '未知', '最近檢測', '尚無檢測紀錄', '自建節點顯示最近五分鐘心跳；第三方節點可查看最近檢測時間。'],
  'en-US': ['Online', 'Offline', 'Awaiting recheck', 'Unknown', 'Last checked', 'Not yet checked', 'Native nodes use the five-minute heartbeat. Select an external node’s status for its last check.'],
  'ja-JP': ['オンライン', 'オフライン', '再確認待ち', '不明', '最終確認', '確認記録なし', '自前ノードは直近5分のハートビートを表示します。外部ノードの状態から最終確認日時を表示できます。'],
  'vi-VN': ['Trực tuyến', 'Ngoại tuyến', 'Chờ kiểm tra lại', 'Chưa rõ', 'Kiểm tra lần cuối', 'Chưa kiểm tra', 'Nút tự quản dùng tín hiệu trong 5 phút. Chọn trạng thái nút bên ngoài để xem lần kiểm tra cuối.'],
  'ko-KR': ['온라인', '오프라인', '재확인 대기', '알 수 없음', '최근 확인', '확인 기록 없음', '자체 노드는 최근 5분의 하트비트를 표시합니다. 외부 노드의 상태에서 최근 확인 시간을 볼 수 있습니다.'],
  'fa-IR': ['آنلاین', 'آفلاین', 'در انتظار بررسی مجدد', 'نامشخص', 'آخرین بررسی', 'هنوز بررسی نشده', 'وضعیت گره‌های داخلی بر اساس پنج دقیقهٔ اخیر است. وضعیت گره خارجی را برای دیدن آخرین بررسی انتخاب کنید.'],
};

const latencyLabels = {
  'zh-CN': ['延迟', '面板服务器经节点到检测站点的请求耗时，非本机延迟。'],
  'zh-TW': ['延遲', '面板伺服器經節點到檢測站點的請求耗時，並非本機延遲。'],
  'en-US': ['Latency', 'Request time from the panel server through this node to the test site, not latency from your device.'],
  'ja-JP': ['遅延', 'パネルサーバーからノード経由で検査先に接続した所要時間です。お使いの端末からの遅延ではありません。'],
  'vi-VN': ['Độ trễ', 'Thời gian yêu cầu từ máy chủ bảng điều khiển qua nút đến trang kiểm tra, không phải từ thiết bị của bạn.'],
  'ko-KR': ['지연 시간', '패널 서버에서 노드를 거쳐 검사 사이트로 보낸 요청 시간이며, 사용자 기기의 지연 시간이 아닙니다.'],
  'fa-IR': ['تأخیر', 'زمان درخواست از سرور پنل از طریق این گره به سایت آزمون است، نه تأخیر دستگاه شما.'],
};

function locale() {
  try {
    const value = JSON.parse(localStorage.getItem('VUE_NAIVE_LOCALE') || 'null')?.value;
    if (Object.hasOwn(labels, value)) return value;
  } catch (_) { /* Same default as the stock dashboard. */ }
  return 'zh-CN';
}

export const healthLegend = () => `${labels[locale()][6]} ${latencyLabels[locale()][1]}`;

let active = null;
let tip = null;

function hide() {
  active?.removeAttribute('aria-describedby');
  active = null;
  tip?.remove();
  tip = null;
}

function show(event) {
  const button = event.currentTarget;
  hide();
  active = button;
  tip = document.createElement('div');
  tip.id = 'xb-external-node-health-tip';
  tip.className = 'xb-external-health-tip';
  tip.setAttribute('role', 'tooltip');
  tip.textContent = button.getAttribute('aria-label');
  document.body.append(tip);
  button.setAttribute('aria-describedby', tip.id);
  const box = button.getBoundingClientRect();
  const maxLeft = Math.max(8, document.documentElement.clientWidth - tip.offsetWidth - 8);
  tip.style.left = `${Math.min(maxLeft, Math.max(8, box.left + (box.width - tip.offsetWidth) / 2))}px`;
  tip.style.top = `${box.top >= tip.offsetHeight + 12 ? box.top - tip.offsetHeight - 6 : box.bottom + 6}px`;
}

document.addEventListener('pointerdown', (event) => { if (active && !active.contains(event.target)) hide(); });
document.addEventListener('keydown', (event) => { if (event.key === 'Escape') hide(); });
document.addEventListener('scroll', hide, true);
window.addEventListener('resize', hide);

// The adapter replaces exactly one Vue node in the known stock bundle. Native dots are untouched.
export function renderExternalHealth(h, node) {
  const lang = locale();
  const dictionary = labels[lang];
  const states = ['online', 'offline', 'pending', 'unknown'];
  const state = states.includes(node.health_status) ? node.health_status : 'unknown';
  const at = typeof node.last_check_at === 'number' && node.last_check_at > 0 ? new Date(node.last_check_at * 1000) : null;
  const checked = at && Number.isFinite(at.getTime())
    ? `${dictionary[4]}：${at.toLocaleString(lang, { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false })}`
    : dictionary[5];
  const latency = state === 'online' && Number.isSafeInteger(node.latency_ms) && node.latency_ms >= 0 ? node.latency_ms : null;
  const details = [dictionary[states.indexOf(state)], checked];
  if (latency !== null) details.push(`${latencyLabels[lang][0]}：${latency} ms`);
  details.push(latencyLabels[lang][1]);
  const children = [h('span', { class: 'xb-external-health-dot', 'aria-hidden': 'true' })];
  if (latency !== null) children.push(h('span', { class: 'xb-external-health-latency', 'aria-hidden': 'true' }, `${latency} ms`));
  return h('button', {
    type: 'button', class: 'xb-external-health', 'data-health': state,
    'aria-label': details.join('\n'),
    onMouseenter: show, onFocus: show, onClick: show,
    onMouseleave: (event) => { if (document.activeElement !== event.currentTarget) hide(); },
    onBlur: hide, onVnodeUnmounted: (vnode) => { if (active === vnode.el) hide(); },
  }, children, 16);
}
