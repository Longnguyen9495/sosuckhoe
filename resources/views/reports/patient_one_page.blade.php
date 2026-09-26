<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<title>Báo cáo tái khám — {{ $patient->full_name }}</title>
<style>
body { font-family: "Segoe UI", Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #333; margin: 24px; }
h1 { font-size: 18px; margin-bottom: 4px; }
.meta { color: #666; font-size: 13px; margin-bottom: 16px; }
.section { margin-bottom: 18px; }
.section h2 { font-size: 15px; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 8px; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { text-align: left; padding: 6px 8px; border: 1px solid #e5e5e5; }
th { background: #f7f7f7; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; }
.badge-red { background: #ffe5e5; color: #c92a5e; }
.badge-good { background: #e6f5ed; color: #16804f; }
.footer { margin-top: 24px; font-size: 12px; color: #999; text-align: center; }
@media print { body { margin: 0; } }
</style>
</head>
<body>
<h1>Báo cáo tái khám</h1>
<div class="meta">
    Bệnh nhân: <strong>{{ $patient->full_name }}</strong> | 
    Năm sinh: {{ $patient->birth_year ?? '—' }} | 
    Giới tính: {{ $patient->gender }}<br>
    Thời gian: {{ $from->format('d/m/Y') }} — {{ $to->format('d/m/Y') }}
</div>

<div class="section">
    <h2>Chỉ số gần nhất</h2>
    @if($latestReading)
    <p>
        <strong>{{ $latestReading->type }}</strong><br>
        Giá trị: {{ json_encode($latestReading->values) }}<br>
        Ngày đo: {{ $latestReading->measured_at->format('d/m/Y H:i') }}<br>
        Đánh giá: <span class="badge {{ $latestReading->evaluation === 'red' ? 'badge-red' : 'badge-good' }}">{{ $latestReading->evaluation ?? '—' }}</span>
    </p>
    @else
    <p>Không có chỉ số trong khoảng thời gian.</p>
    @endif
</div>

<div class="section">
    <h2>Cảnh báo đỏ ({{ $redAlerts->count() }})</h2>
    @if($redAlerts->count() > 0)
    <table>
        <tr><th>Thời gian</th><th>Nội dung</th></tr>
        @foreach($redAlerts as $alert)
        <tr>
            <td>{{ \Carbon\Carbon::parse($alert->created_at)->format('d/m/Y H:i') }}</td>
            <td>{{ $alert->content }}</td>
        </tr>
        @endforeach
    </table>
    @else
    <p>Không có cảnh báo đỏ.</p>
    @endif
</div>

<div class="section">
    <h2>Tuân thủ điều trị</h2>
    <p>Tỷ lệ hoàn thành trong khoảng: <strong>{{ $adherence }}%</strong></p>
</div>

<div class="section">
    <h2>Nhận xét bác sĩ</h2>
    @if($doctorNotes->count() > 0)
    <table>
        <tr><th>Ngày</th><th>Nội dung</th></tr>
        @foreach($doctorNotes as $note)
        <tr>
            <td>{{ \Carbon\Carbon::parse($note->created_at)->format('d/m/Y H:i') }}</td>
            <td>{{ $note->content }}</td>
        </tr>
        @endforeach
    </table>
    @else
    <p>Chưa có nhận xét.</p>
    @endif
</div>

<div class="footer">
    Báo cáo được tạo từ hệ thống Sổ Theo Dõi Sức Khỏe — {{ now()->format('d/m/Y H:i') }}
</div>
</body>
</html>
