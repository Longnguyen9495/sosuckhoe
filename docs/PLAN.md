# Kế hoạch phần mềm theo dõi sức khỏe (nhiều khách hàng)

Cập nhật: 26/09/2026

Mục tiêu: xây một nền tảng theo dõi điều trị tại nhà, giao diện kiểu Snapask, cho nhiều khách hàng dùng chung. Khách hàng có thể là gia đình chăm người bệnh hoặc phòng khám theo dõi nhiều bệnh nhân. Dữ liệu của mỗi khách tách riêng hoàn toàn.

Khách hàng đầu tiên là gia đình bà D. (nữ, 58 tuổi), khám tại một bệnh viện ở Hà Nội ngày 26/09/2026. Bà bị tiểu đường típ 2 phải dùng insulin, viêm gan B mới bắt đầu điều trị, kèm 8 vấn đề khác. Ca này vừa là người dùng thật vừa là bộ dữ liệu mẫu để kiểm thử.

Tài liệu gồm 2 phần:
- **Phần A (mục 1–4): ca bệnh mẫu.** Phân tích 28 ảnh hồ sơ, phác đồ, lịch theo dõi. Đây là dữ liệu mẫu ban đầu (seed) và là tình huống kiểm thử chuẩn cho bộ sinh lịch.
- **Phần B (mục 5–11): sản phẩm.** Khách hàng, luồng đăng ký, bộ sinh lịch, giao diện, kiến trúc nhiều khách hàng, pháp lý, lộ trình.

Bản mẫu đã chạy được: [index.html](index.html) và [api.php](api.php). Bản mẫu này chỉ phục vụ một bệnh nhân, dữ liệu viết thẳng trong code. Nó được giữ lại làm tham chiếu giao diện, không phát triển tiếp.

> Nguyên tắc y khoa: phần mềm là **sổ theo dõi**. Nó chỉ sắp lại lịch theo đúng đơn bác sĩ kê, không đổi liều, không chẩn đoán, không khuyên dùng thuốc. Mọi ngưỡng cảnh báo mặc định đều phải được bác sĩ hoặc người dùng xác nhận cho từng bệnh nhân.

---

# Phần A — Ca bệnh mẫu: bà D.

## 1. Phân tích từng ảnh

Thư mục ảnh gốc (chỉ lưu trên máy, không đưa lên git) có 28 ảnh: 24 phiếu khác nhau và 4 ảnh chụp trùng. Bản thu nhỏ đã tự xoay đúng chiều nằm ở `assets/img/`.

| Ảnh | Loại phiếu | Phát hiện chính | Dữ liệu phần mềm cần lấy ra |
| --- | --- | --- | --- |
| IMG_1308 (trùng 1332) | Đơn thuốc số 2, khoa Nội tiết | Janumet 50/850: sáng 1, tối 1. NovoMix 30: sáng 16 UI, tối 14 UI, tiêm trước ăn 5 phút. Esserose: sáng 1, tối 1. Lời dặn: duy trì thuốc huyết áp và mỡ máu, cấy nước tiểu, tái khám sau 1 tháng | 3 thuốc, 1 lịch tái khám, 1 xét nghiệm cần làm |
| IMG_1309 (trùng 1336) | Đơn thuốc số 3, Viêm gan B | Hepazid (TAF) 25 mg: 1 viên/ngày, bác sĩ ghi tay "đúng giờ, hằng ngày, không bỏ". Livosil 140 mg: sáng 1, tối 1, kê 180 viên. Tái khám sau 3 tháng | 2 thuốc dùng lâu dài, cần nhắc đúng giờ |
| IMG_1310 (trùng 1326, 1331) | Đơn thuốc, khoa Phục hồi chức năng | Celebrex 200 mg sáng. Abricotis (Ca + D3) sáng, trưa. Oztis và Myopain sáng, tối. Etiheso 40 mg trước ăn sáng 1 giờ. Dặn khám Nội tiết, Hô hấp, Truyền nhiễm và tập phục hồi chức năng | 5 thuốc trong đợt 30 ngày, 3 chuyên khoa cần khám |
| IMG_1327 | Phiếu tư vấn gel Nociceptol | Bôi chỗ đau 3 lần/ngày | 1 việc lặp lại 3 lần/ngày |
| IMG_1333 | Phiếu tư vấn kim NovoFine 31G | 30 kim, thay kim sau 1–2 lần tiêm | Theo dõi số kim còn lại |
| IMG_1313 | Xét nghiệm sinh hóa và miễn dịch | HBsAg 1811 COI (dương tính). HbA1c 10,16%. Glucose 8,8. ALT 73, AST 42. Acid uric 351. Vitamin D 28. Bác sĩ ghi tay "tiêm phòng cúm, phế cầu" | Chỉ số nền, 2 mũi tiêm phòng |
| IMG_1314 | Công thức máu | Hồng cầu 6,62 (tăng). MCV 66,8 và MCH 20 (giảm). RDW 18,1. Hb 134 (bình thường) | Cờ "hỏi bác sĩ": nghi thalassemia |
| IMG_1316 | Tổng phân tích nước tiểu | Bạch cầu niệu 100 Leu/µL (dương tính), nitrit âm tính | Căn cứ cho việc cấy nước tiểu |
| IMG_1317 | X-quang cột sống cổ | Giảm độ cong sinh lý, có mỏ xương thoái hóa | Ghi nhận chẩn đoán |
| IMG_1319 | X-quang tim phổi | Nốt mờ hạ đòn trái, quai động mạch chủ vồng | Việc cần làm: khám Hô hấp |
| IMG_1320 | Siêu âm ổ bụng | Gan nhiễm mỡ, nang thận phải 11 mm | Nhắc siêu âm lại sau 6–12 tháng |
| IMG_1312 | Siêu âm vai phải | Vôi hóa gân trên gai 4 mm, dịch quanh gân nhị đầu 2 mm, thoái hóa khớp cùng vai đòn | Ghi nhận chẩn đoán |
| IMG_1322 | Siêu âm vai trái | Dịch quanh gân nhị đầu 2 mm, thoái hóa khớp cùng vai đòn | Ghi nhận chẩn đoán |
| IMG_1323 (trùng 1334) | Kết quả khám Nội tiết | Tiểu đường hơn 10 năm. Mạch 106, huyết áp 124/96. Gia đình có người tăng huyết áp | Chỉ số sinh hiệu nền |
| IMG_1328, 1329, 1330 | Kết quả khám PHCN (3 trang) | Tổng hợp lại toàn bộ xét nghiệm và chẩn đoán hình ảnh phía trên | Chỉ để đối chiếu, không có dữ liệu mới |
| IMG_1337 | Kết quả khám Viêm gan B | Trước đây chưa điều trị, nay có chỉ định thuốc kháng virus lâu dài. Bác sĩ đề nghị đếm virus và sàng lọc ung thư gan; gia đình xin làm ở bệnh viện tỉnh | 1 xét nghiệm có hạn |
| IMG_1335 | Bảng kê chi phí | Hepazid 90 viên: 1.726.200 đ. Livosil chỉ mua 90/180 viên. Tổng 2.340.900 đ | Số thuốc thực mua khác số trong đơn |
| IMG_1324 | Phiếu theo dõi đường huyết | Mục tiêu bác sĩ ghi: trước ăn 4,4–7,2 mmol/L, sau ăn 2 giờ dưới 10 mmol/L. Ngày 26/9 có ghi insulin trưa 6 đơn vị | Ngưỡng cảnh báo, mẫu sổ cần số hóa |
| IMG_1325, 1339 | Hướng dẫn ăn cho người tiểu đường | Hạn chế đường, quả ngọt, tinh bột trắng. 3 bữa chính cố định giờ, ăn phụ lúc 10h và 15h | Nội dung cho thư viện hướng dẫn |
| IMG_1338 | Chế độ ăn giảm acid uric | Uống nhiều nước. Hạn chế phủ tạng, hải sản, thịt đỏ, rượu bia | Nội dung cho thư viện hướng dẫn |

---

## 2. Vấn đề sức khỏe

Cần theo dõi sát nhất 2 bệnh:
- **Tiểu đường:** HbA1c 10,16%, vừa chuyển sang tiêm insulin nên có nguy cơ hạ đường huyết.
- **Viêm gan B:** vừa bắt đầu thuốc kháng virus, không được bỏ liều.

Có 3 việc bác sĩ dặn mà gia đình chưa làm: cấy nước tiểu, khám Hô hấp, xét nghiệm bổ sung cho viêm gan B.

| Ưu tiên | Vấn đề | Chỉ số chính | Việc cần làm |
| --- | --- | --- | --- |
| Cao | Tiểu đường típ 2, đã hơn 10 năm, chưa kiểm soát được | HbA1c 10,16% (bình thường 4,8–5,9). Glucose lúc đói 8,8 mmol/L | Đo đường huyết hằng ngày, tiêm insulin đúng giờ, luôn sẵn sàng xử trí hạ đường huyết |
| Cao | Viêm gan B mạn, men gan tăng | HBsAg 1811 COI. ALT 73 U/L (khoảng 2,2 lần mức bình thường). AST 42 U/L | Uống Hepazid cùng một giờ mỗi ngày. Làm HBV-DNA, HBeAg, AFP, Fibroscan |
| Cao | Nốt mờ hạ đòn trái trên X-quang | Chưa rõ bản chất | Khám Hô hấp trong 2 tuần đầu |
| Trung bình | Nghi nhiễm trùng tiết niệu | Bạch cầu niệu 100 Leu/µL, nitrit âm tính | Cấy nước tiểu, uống đủ nước |
| Trung bình | Tăng huyết áp, rối loạn mỡ máu | Huyết áp 124/96, mạch 106, LDL 2,59 mmol/L | Tiếp tục thuốc đang dùng (không có trong đơn mới), đo huyết áp hằng ngày |
| Trung bình | Gan nhiễm mỡ | Siêu âm thấy nhu mô tăng âm | Uống Esserose, ăn ít mỡ, không rượu bia |
| Trung bình | Thoái hóa cột sống cổ, viêm quanh khớp 2 vai, thiếu vitamin D | Vôi hóa gân 4 mm ở vai phải. Vitamin D 28 ng/mL | 5 thuốc trong 30 ngày, gel bôi, tập phục hồi chức năng |
| Thấp | Tăng acid uric, chưa có gút | 351 µmol/L (bình thường ≤ 339,2) | Chế độ ăn giảm purin |
| Thấp | Hồng cầu nhỏ nhưng số lượng cao | MCV 66,8, chỉ số Mentzer khoảng 10 | Hỏi bác sĩ về xét nghiệm ferritin và điện di huyết sắc tố. Không tự uống sắt |
| Thấp | Nang thận phải 11 mm | Thành mỏng, dịch trong. eGFR 101 | Siêu âm lại sau 6–12 tháng |

---

## 3. Phác đồ điều trị

Gồm 11 thuốc và vật tư, cộng 2 mũi insulin mỗi ngày, lấy nguyên từ 3 đơn thuốc. Ngày hết thuốc được tính từ 27/09/2026.

| Thuốc | Dùng cho | Liều và giờ dùng | Số lượng | Dự kiến hết | Loại |
| --- | --- | --- | --- | --- | --- |
| NovoMix 30 FlexPen | Tiểu đường | Sáng 16 UI, tối 14 UI, tiêm trước ăn 5 phút | 4 bút = 1.200 UI (34 UI/ngày, tính cả 2 UI thử kim mỗi lần tiêm) | 31/10 | Dài hạn |
| Kim NovoFine 31G | Tiểu đường | Thay kim sau 1–2 lần tiêm | 30 cái | 26/10 | Dài hạn |
| Janumet 50/850 mg | Tiểu đường | Sáng 1, tối 1, sau ăn | 60 viên | 26/10 | Dài hạn |
| Esserose 450 mg | Gan nhiễm mỡ | Sáng 1, tối 1, sau ăn | 60 viên | 26/10 | Dài hạn |
| Hepazid 25 mg (TAF) | Viêm gan B | 1 viên/ngày sau ăn, cùng giờ mỗi ngày | 90 viên | 25/12 | Dài hạn |
| Livosil 140 mg | Viêm gan B | Sáng 1, tối 1, sau ăn | Mới mua 90, đơn kê 180 viên | 10/11 | Dài hạn |
| Etiheso 40 mg | Bảo vệ dạ dày | Buổi sáng, trước ăn 1 giờ | 30 viên | 26/10 | Đợt 30 ngày |
| Celebrex 200 mg | Đau cổ vai | 1 viên sau khi ăn sáng no | 30 viên | 26/10 | Đợt 30 ngày |
| Abricotis (Ca + D3) | Thiếu vitamin D | Sáng 1, trưa 1, sau ăn | 60 viên | 26/10 | Đợt 30 ngày |
| Oztis | Khớp | Sáng 1, tối 1, sau ăn | 60 viên | 26/10 | Đợt 30 ngày |
| Myopain 50 mg | Giãn cơ | Sáng 1, tối 1, sau ăn | 60 viên | 26/10 | Đợt 30 ngày |
| Gel Nociceptol | Đau tại chỗ | Bôi 3 lần/ngày | 1 tuýp 120 ml | Theo đợt 30 ngày | Đợt 30 ngày |
| Thuốc huyết áp và mỡ máu đang dùng | Tăng huyết áp, mỡ máu | Tiếp tục như cũ | Chưa biết tên | — | Dài hạn |

Quy tắc an toàn phần mềm phải hiển thị:

- **Hạ đường huyết (dưới 3,9 mmol/L):** ăn ngay 15 g đường nhanh, đo lại sau 15 phút, lặp lại nếu vẫn thấp. Nếu người bệnh lơ mơ thì gọi 115 và không cho ăn uống bằng miệng.
- **Hepazid:** không được tự ngừng thuốc vì viêm gan có thể bùng phát. Quên liều thì uống ngay khi nhớ ra trong ngày, không uống 2 viên trong một ngày.
- **Celebrex:** có thể làm tăng huyết áp. Nếu đi ngoài phân đen hoặc đau vùng thượng vị thì ngừng thuốc và đi khám.
- **Bảo quản insulin:** bút chưa dùng để ngăn mát tủ lạnh (2–8 °C). Bút đang dùng để ở nhiệt độ dưới 30 °C, dùng tối đa 4 tuần.

---

## 4. Lịch theo dõi từng ngày

Mỗi ngày có khoảng 25 việc cố định, từ 06:00 đến 21:30. Số lần đo đường huyết giảm dần qua 3 giai đoạn. Lịch đo này là đề xuất, cần bác sĩ nội tiết xác nhận.

### 4.1 Khung giờ một ngày

| Giờ | Việc |
| --- | --- |
| 06:00 | Uống Etiheso (trước ăn sáng 1 giờ) |
| 06:45 | Đo đường huyết lúc đói. 06:50 đo huyết áp và mạch |
| 06:55 | Tiêm NovoMix 16 UI |
| 07:00 | Ăn sáng |
| 07:30 | Uống 7 viên: Janumet, Celebrex, Esserose, Livosil, Abricotis, Oztis, Myopain. Bôi gel |
| 08:00 | Đi bộ nhẹ 10–15 phút |
| 10:00 | Bữa phụ nhỏ |
| 12:00 | Ăn trưa. 12:30 uống Hepazid và Abricotis. Bôi gel |
| 15:00 | Bữa phụ. 15:30 tập bài cổ vai |
| 18:25 | Tiêm NovoMix 14 UI. 18:30 ăn tối |
| 19:00 | Uống 5 viên: Janumet, Esserose, Livosil, Oztis, Myopain. Bôi gel. Đi bộ |
| 21:00–21:30 | Đo huyết áp buổi tối và đường huyết trước khi ngủ (chỉ trong giai đoạn 1) |

Hepazid được xếp vào 12:30 để bữa sáng bớt số viên phải uống. Có thể đổi sang giờ khác, miễn là uống cùng một giờ mỗi ngày.

### 4.2 Giai đoạn đo đường huyết

| Giai đoạn | Thời gian | Đo khi nào |
| --- | --- | --- |
| 1. Tăng cường | 27/9 – 10/10 | 4 lần/ngày: lúc đói, trước bữa trưa, trước bữa tối, trước khi ngủ. Đo huyết áp sáng và tối |
| 2. Ổn định | 11/10 – 26/10 | Lúc đói và trước bữa tối mỗi ngày. Chủ nhật đo đủ 6 điểm (trước và 2 giờ sau mỗi bữa) |
| 3. Duy trì | Từ 27/10 | Lúc đói mỗi ngày, trước bữa tối vào thứ 2, 4, 6. Điều chỉnh theo bác sĩ |

### 4.3 Ngưỡng đánh giá

| Chỉ số | Đạt | Cần chú ý | Cảnh báo đỏ |
| --- | --- | --- | --- |
| Đường huyết trước ăn / trước ngủ (mmol/L) | 4,4–7,2 | 3,9–4,4 hoặc 7,2–10 | Dưới 3,9 hoặc trên 10 (trên 16,7 là rất nguy hiểm) |
| Đường huyết 2 giờ sau ăn (mmol/L) | Dưới 10 | 10–16,7 | Dưới 3,9 hoặc trên 16,7 |
| Huyết áp (mmHg) | Dưới 130/80 | 130–159 / 80–99 | Từ 160/100 trở lên, hoặc dưới 90/60 |
| Mạch khi nghỉ (lần/phút) | 60–100 | 100–120 | Trên 120 hoặc dưới 50 |

### 4.4 Mốc trong 3 tháng

| Hạn | Việc |
| --- | --- |
| 30/09 | Cấy nước tiểu gần nhà, làm trước khi dùng kháng sinh |
| 03/10 | Xét nghiệm viêm gan B tại bệnh viện tỉnh: HBV-DNA, HBeAg, AFP, siêu âm gan, Fibroscan |
| 10/10 | Khám Hô hấp vì nốt mờ ở phổi. Tổng kết số liệu đường huyết giai đoạn 1 |
| 12/10 | Tiêm phòng cúm, hỏi bác sĩ về vắc xin phế cầu |
| 20/10 | Mua thêm kim tiêm |
| 24/10 | Tái khám Phục hồi chức năng (sáng thứ 7) |
| 26/10 | Tái khám Nội tiết. Xin kê tiếp insulin, Janumet, Esserose |
| 05/11 | Mua thêm 90 viên Livosil |
| 26/11 | Tái khám Nội tiết lần 2 (dự kiến) |
| 18/12 | Đặt lịch tái khám viêm gan B, vì Hepazid hết vào 25/12 |
| 25/12 | Tái khám viêm gan B sau 3 tháng. Xét nghiệm HbA1c, ALT, HBV-DNA |

Theo dõi dài hạn (đề xuất, cần hỏi bác sĩ):
- HbA1c mỗi 3 tháng.
- Siêu âm gan và AFP mỗi 6 tháng.
- Siêu âm lại nang thận.
- Mỗi năm: soi đáy mắt, khám bàn chân, xét nghiệm albumin niệu, tiêm cúm.


---

# Phần B — Sản phẩm cho nhiều khách hàng

## 5. Khách hàng, vai trò và tính năng

### 5.1 Loại khách hàng

Mỗi khách hàng là một **tài khoản** (trong code gọi là `tenant`). Mọi dữ liệu đều thuộc về đúng một tài khoản.

| Loại tài khoản | Ví dụ | Số bệnh nhân | Ai trả tiền (đề xuất) |
| --- | --- | --- | --- |
| Gia đình | Con cái chăm bố mẹ, như gia đình bà D. | 1–5 | Miễn phí 1 bệnh nhân, thu phí từ bệnh nhân thứ 2 |
| Phòng khám / bác sĩ | Phòng khám nội tiết theo dõi bệnh nhân tiểu đường | Hàng chục đến hàng trăm | Thu phí theo số bệnh nhân đang theo dõi |

Một gia đình có thể mời bác sĩ vào xem hồ sơ. Một phòng khám có thể mời người nhà bệnh nhân vào ghi chỉ số. Hai loại tài khoản dùng chung một hệ thống, chỉ khác gói và quyền.

### 5.2 Vai trò

| Vai trò | Phạm vi | Được làm gì |
| --- | --- | --- |
| Chủ tài khoản (`owner`) | Cả tài khoản | Mọi quyền: thêm bệnh nhân, mời / xoá thành viên, xoá dữ liệu, gói dịch vụ |
| Người chăm sóc (`caregiver`) | Các bệnh nhân được giao | Xem, tích lịch, ghi chỉ số, nhập / sửa đơn thuốc, ghi chú |
| Bệnh nhân (`patient`) | Hồ sơ của chính mình | Xem, tích lịch, ghi chỉ số |
| Bác sĩ (`doctor`) | Các bệnh nhân được giao | Xem toàn bộ, ghi nhận xét, xác nhận ngưỡng và lịch đo; không sửa nhật ký |
| Người xem (`viewer`) | Các bệnh nhân được giao | Chỉ xem |
| Quản trị hệ thống (`super_admin`) | Toàn hệ thống (đội vận hành) | Quản lý danh mục thuốc, mẫu theo bệnh, hỗ trợ khách; **không** xem dữ liệu bệnh nhân nếu khách chưa cho phép |

Quyền được cấp theo từng bệnh nhân, không cấp cho cả tài khoản (trừ chủ tài khoản). Nhờ vậy phòng khám có thể giao mỗi bác sĩ một nhóm bệnh nhân riêng.

### 5.3 Luồng đăng ký khách mới

Mục tiêu: một gia đình tự tạo xong hồ sơ và có lịch dùng được trong **15 phút**, không cần ai hỗ trợ.

1. **Đăng ký:** số điện thoại hoặc email, xác thực bằng OTP.
2. **Đồng ý xử lý dữ liệu:** đọc và đồng ý điều khoản, chính sách dữ liệu sức khỏe. Lưu lại phiên bản và thời điểm đồng ý.
3. **Tạo hồ sơ bệnh nhân:** họ tên, năm sinh, giới tính, dị ứng thuốc. Chọn bệnh nền từ danh sách mẫu (mục 5.5).
4. **Giờ sinh hoạt:** giờ thức dậy, giờ 3 bữa chính, giờ đi ngủ. Bộ sinh lịch dựa vào các giờ này.
5. **Nhập đơn thuốc** bằng một trong hai cách:
    - Chọn thuốc từ danh mục, rồi chọn cách dùng ("sáng 1, tối 1, sau ăn"…).
    - Chụp ảnh đơn → AI đề xuất từng dòng → người dùng duyệt từng dòng (giai đoạn 5).
6. **Xem trước lịch:** hệ thống sinh lịch một ngày mẫu, người dùng sửa giờ nếu cần rồi xác nhận.
7. **Mời thành viên:** gửi link mời người nhà hoặc bác sĩ, chọn vai trò.

Sau mỗi lần tái khám, người dùng lặp lại bước 5–6 với đơn mới. Đơn cũ được đóng lại, lịch tự cập nhật từ ngày bắt đầu của đơn mới.

### 5.4 Bộ sinh lịch

Bộ sinh lịch biến "cách dùng" trên đơn thành các việc có giờ cụ thể, dựa trên giờ sinh hoạt của từng bệnh nhân. Đây là phần lõi thay cho mảng `DAY_ITEMS` viết tay trong bản mẫu.

Mỗi dòng thuốc lưu cách dùng dưới dạng JSON, ví dụ Janumet của bà D.:

```json
{
  "doses": [
    { "anchor": "breakfast", "offset_min": 30, "amount": 1, "unit": "viên" },
    { "anchor": "dinner",    "offset_min": 30, "amount": 1, "unit": "viên" }
  ],
  "with_food": "after",
  "days": "daily"
}
```

Bảng chuyển từ cách ghi trên đơn sang quy tắc:

| Cách ghi trên đơn | Mốc | Lệch giờ mặc định | Ghi chú |
| --- | --- | --- | --- |
| Sáng / trưa / tối, sau ăn | Bữa sáng / trưa / tối | +30 phút | Phổ biến nhất |
| Trước ăn 1 giờ | Bữa tương ứng | −60 phút | Ví dụ Etiheso |
| Tiêm ngay trước ăn 5 phút | Bữa tương ứng | −5 phút | Insulin trộn sẵn; đánh dấu loại `insulin` |
| Ngày 1 lần, cùng giờ | Người dùng chọn | Cố định | Thuốc kháng virus; nhắc mạnh hơn nếu trễ |
| Trước khi ngủ | Giờ ngủ | −30 phút | |
| Bôi 3 lần/ngày | Sáng, trưa, tối | +40 phút | Loại `topical` |
| Khi đau / khi cần | Không có | — | Không nhắc; chỉ có nút "đã dùng" và giới hạn số lần trong ngày |
| Cách ngày, thứ 2-4-6 | Theo ngày | — | Trường `days` |

Quy tắc bắt buộc:
- Liều và số lượng lấy nguyên từ đơn. Bộ sinh lịch **chỉ quyết định giờ**, không bao giờ tính hay đổi liều.
- Các việc trùng giờ được gộp thành một nhóm, ví dụ "07:30 — 7 viên".
- Nếu không hiểu cách dùng, hệ thống bắt người dùng chọn giờ bằng tay, không tự đoán.
- Mỗi việc trong lịch ghi rõ sinh ra từ dòng thuốc nào của đơn nào, để truy vết khi có sai sót.
- Kiểm thử chuẩn: nhập 3 đơn của bà D. với giờ sinh hoạt 07:00 / 12:00 / 18:30 / 22:00 thì phải ra đúng khung giờ ở mục 4.1.

### 5.5 Mẫu theo bệnh và ngưỡng cho từng người

Mỗi bệnh nền có một **mẫu** do đội vận hành soạn. Khi gán bệnh cho bệnh nhân, hệ thống chép mẫu thành cấu hình riêng của bệnh nhân đó, và người dùng hoặc bác sĩ sửa được.

| Mẫu (bản đầu) | Chỉ số theo dõi | Lịch đo mặc định | Ngưỡng mặc định |
| --- | --- | --- | --- |
| Tiểu đường có insulin | Đường huyết | 3 giai đoạn như mục 4.2 | Trước ăn 4,4–7,2; sau ăn < 10; dưới 3,9 là cảnh báo đỏ |
| Tiểu đường chỉ uống thuốc | Đường huyết | Lúc đói hằng ngày, 1 ngày/tuần đo 4 điểm | Như trên |
| Tăng huyết áp | Huyết áp, mạch | Sáng và tối | < 130/80; ≥ 160/100 là cảnh báo đỏ |
| Viêm gan B đang điều trị | (Không đo tại nhà) | — | Nhắc uống thuốc cùng giờ, nhắc xét nghiệm 3–6 tháng |
| Rối loạn mỡ máu, tăng acid uric | (Không đo tại nhà) | — | Nội dung chế độ ăn, nhắc xét nghiệm |

Mỗi mẫu còn có sẵn: danh sách dấu hiệu nguy hiểm, bài hướng dẫn (ăn uống, xử trí hạ đường huyết, cách tiêm insulin), câu hỏi gợi ý cho bác sĩ.

Ngưỡng lấy từ mẫu hiện nhãn **"Mặc định — chưa được bác sĩ xác nhận"** cho tới khi một người có vai trò bác sĩ hoặc chủ tài khoản xác nhận. Lịch sử thay đổi ngưỡng được lưu lại.

### 5.6 Tính năng theo giai đoạn

| Tính năng | Giai đoạn | Bản mẫu đã có |
| --- | --- | --- |
| Tài khoản, mời thành viên, vai trò theo bệnh nhân | 1 | Chưa |
| Chuyển qua lại giữa nhiều bệnh nhân | 1 | Chưa |
| Nhập dữ liệu mẫu bà D. từ bản mẫu | 1 | — |
| Bộ sinh lịch từ đơn + giờ sinh hoạt | 2 | Chưa (đang viết tay) |
| Lịch trong ngày, tích đã làm, ghi chỉ số, đánh giá theo ngưỡng riêng | 2 | Có (ngưỡng viết cứng) |
| Lịch tháng, mốc khám / xét nghiệm / mua thuốc, biểu đồ, xuất CSV | 2 | Có |
| Cài lên điện thoại (PWA), thông báo đẩy nhắc giờ | 2 | Chưa |
| Luồng đăng ký 7 bước, danh mục thuốc, 5 mẫu bệnh | 3 | Chưa |
| Nhập / đóng đơn thuốc, tồn kho theo số đã mua | 3 | Có một phần |
| Hồ sơ: tải ảnh phiếu khám, bảng xét nghiệm | 3 | Có (ảnh cố định) |
| Câu hỏi cho bác sĩ | 3 | Có |
| Trang bác sĩ: danh sách bệnh nhân, cờ đỏ, báo cáo PDF | 4 | Chưa |
| Tài khoản phòng khám, giao bệnh nhân cho từng bác sĩ | 4 | Chưa |
| Chụp đơn thuốc để AI đọc, người dùng duyệt | 5 | Chưa |
| Gửi Zalo ZNS / SMS khi có cảnh báo đỏ | 5 | Chưa |

---

## 6. Khung giao diện kiểu Snapask

Lấy 4 đặc trưng của Snapask:
- Đầu trang màu tím đậm, bo tròn ở cạnh dưới.
- Thẻ nội dung màu trắng, bo góc lớn.
- Thanh tab dưới đáy, có nút hành động nổi ở giữa.
- Phần hỏi đáp hiển thị dạng bong bóng chat.

Không dùng logo hay tên Snapask. Bản mẫu [index.html](index.html) là tham chiếu trực quan cho các màn hình của người chăm sóc.

Điểm mới so với bản mẫu: ảnh đại diện ở góc trái đầu trang thành **nút chọn bệnh nhân**, giống cách Snapask cho chuyển hồ sơ. Chạm vào sẽ mở danh sách bệnh nhân của tài khoản, kèm số việc chưa làm hôm nay và cờ đỏ.

```
┌─────────────────────────────┐
│ [D ▾] Bà D.      (chat)(◐)  │  ← chạm [D ▾] để đổi bệnh nhân
│ Hôm nay của mẹ              │
│ Ngày 3 của phác đồ          │
├─────────────────────────────┤
│ ( 65% )  ĐH đói | HA | Nước │
│ [T7][CN][T2][T3][T4] …      │
│ ! Cảnh báo / mốc hôm nay    │
│ SÁNG ─────────────────────  │
│ 06:00 Etiheso          [✓]  │
│ 06:45 Đường huyết [6,5] Đạt │
├─────────────────────────────┤
│ Hôm nay  Lịch  (+)  Phác đồ  Hồ sơ │
└─────────────────────────────┘
```

### 6.1 Màn hình cho gia đình / người chăm sóc

| Màn hình | Mở từ đâu | Thành phần chính |
| --- | --- | --- |
| Đăng ký | Lần đầu mở app | Luồng 7 bước (mục 5.3), mỗi bước một thẻ, thanh tiến độ trên cùng, nút "Làm sau" cho bước mời thành viên |
| Chọn bệnh nhân | Ảnh đại diện góc trái | Danh sách thẻ bệnh nhân: % hôm nay, cờ đỏ, nút "+ Thêm bệnh nhân" |
| Hôm nay | Tab 1 | Như bản mẫu: vòng %, 3 ô chỉ số, dải ngày, cảnh báo, dòng thời gian có nút tích, nước uống, dấu hiệu bất thường, ghi chú |
| Lịch | Tab 2 | Lịch tháng có chấm màu và thanh %, giai đoạn đo, biểu đồ, danh sách mốc |
| Ghi chỉ số | Nút + ở giữa | Bảng trượt lên từ dưới; thời điểm đo cần làm được gợi ý trước |
| Phác đồ | Tab 3 | Thẻ theo bệnh, thẻ thuốc có tồn kho; nút "Nhập đơn mới" và "Kết thúc đơn" |
| Nhập đơn | Từ Phác đồ | Tìm thuốc trong danh mục, chọn cách dùng bằng nút bấm, xem trước lịch trước khi lưu |
| Hồ sơ | Tab 4 | Tải ảnh phiếu, chọn loại phiếu, bảng xét nghiệm (nhập tay, sau này AI đọc) |
| Hỏi bác sĩ | Nút chat góc trên | Câu hỏi theo bác sĩ dạng bong bóng; nếu bác sĩ có trong hệ thống thì bác sĩ trả lời trực tiếp trong luồng |
| Cài đặt | Trong menu bệnh nhân | Giờ sinh hoạt, ngưỡng (có nhãn đã / chưa xác nhận), thành viên và vai trò, xuất / xoá dữ liệu |

### 6.2 Màn hình cho bác sĩ / phòng khám (giai đoạn 4)

| Màn hình | Thành phần chính |
| --- | --- |
| Danh sách bệnh nhân | Bảng sắp theo mức cảnh báo: cờ đỏ 7 ngày qua, % tuân thủ thuốc, chỉ số gần nhất, ngày tái khám |
| Hồ sơ bệnh nhân | Biểu đồ 30 / 90 ngày, nhật ký, đơn đang dùng; nút "Xác nhận ngưỡng", "Sửa lịch đo", "Ghi nhận xét" |
| Báo cáo | Xuất PDF một trang cho buổi tái khám |

Trên máy tính, màn hình bác sĩ dùng bố cục 2 cột (danh sách bên trái, hồ sơ bên phải), vẫn giữ màu và thẻ của hệ thiết kế.

### 6.3 Token thiết kế

| Token | Chế độ sáng | Chế độ tối | Dùng cho |
| --- | --- | --- | --- |
| `--brand` | #6C4CF1 | #8F78FF | Đầu trang, nút chính, tab đang chọn |
| `--accent` | #FFC940 | #FFC940 | Ảnh đại diện, chip hạn chót |
| `--good` / `--warn` / `--bad` | #16804F / #9A6200 / #C92A5E | #4FD08E / #F2B84B / #FF7AA2 | Đạt / cần chú ý / cảnh báo; luôn kèm chữ, không chỉ dùng màu |
| `--bg` / `--card` | #F5F3FB / #FFFFFF | #13111C / #1E1B2B | Nền trang, nền thẻ |
| `--s1` / `--s2` | #6C4CF1 / #C96A0A | #8F78FF / #D27D1C | Hai đường biểu đồ (đã kiểm tra dễ phân biệt với người mù màu) |

Quy ước chung:
- Font Be Vietnam Pro, chữ thân 15 px; có tuỳ chọn cỡ chữ lớn cho người cao tuổi.
- Thẻ bo góc 20 px, vùng bấm tối thiểu 44 px (bản mẫu đang là 30 px, cần tăng).
- Trang rộng tối đa 720 px cho màn hình gia đình, không cuộn ngang trên điện thoại.
- Có chế độ tối.
- Mọi chữ hiển thị đặt trong file ngôn ngữ, để sau này thêm tiếng Anh.

---

## 7. Kiến trúc nhiều khách hàng

### 7.1 Công nghệ

Đề xuất chuyển từ PHP thuần sang **Laravel**. Hệ thống nhiều khách hàng cần sẵn: đăng nhập, phân quyền, migration cơ sở dữ liệu, hàng đợi gửi thông báo, lịch chạy định kỳ. Viết tay những phần này bằng PHP thuần vừa lâu vừa dễ sót lỗ hổng.

| Lớp | Công nghệ | Ghi chú |
| --- | --- | --- |
| Backend | Laravel 11, PHP 8.3 | Sanctum cho đăng nhập; Policy cho quyền theo bệnh nhân |
| Cơ sở dữ liệu | MySQL 8 | Một cơ sở dữ liệu dùng chung, mọi bảng có `tenant_id` |
| Hàng đợi, cache | Redis | Gửi thông báo, sinh lịch, xử lý ảnh |
| Frontend | PWA, tách JS / CSS từ bản mẫu; có thể dùng Vue 3 khi màn hình nhiều lên | Service worker, chạy khi mất mạng, thông báo đẩy |
| Lưu ảnh | Kho lưu trữ tương thích S3, có mã hoá | Ảnh phiếu khám không để trong thư mục web |
| Thông báo | Web Push, email; giai đoạn 5 thêm Zalo ZNS / SMS | Zalo ZNS cần tài khoản doanh nghiệp |
| Đọc ảnh đơn (giai đoạn 5) | Mô hình AI đọc ảnh | Chỉ trả về đề xuất, người dùng duyệt từng dòng |
| Máy chủ | VPS đặt tại Việt Nam, HTTPS, sao lưu hằng ngày giữ 30 ngày | Để trong nước giúp tránh thủ tục chuyển dữ liệu ra nước ngoài |

XAMPP trên máy cá nhân chỉ dùng để phát triển, không dùng để phục vụ khách.

### 7.2 Cách tách dữ liệu giữa các khách hàng

- Mọi bảng chứa dữ liệu của khách đều có cột `tenant_id`. Bảng gắn với bệnh nhân có thêm `patient_id`.
- Laravel dùng một **global scope** tự thêm điều kiện `tenant_id` vào mọi truy vấn. Không truy vấn nào được bỏ qua scope này, trừ trong code quản trị đã được kiểm tra riêng.
- Mọi API có `{patient}` đều qua Policy kiểm tra người dùng có quyền với bệnh nhân đó (bảng `patient_access`).
- Id công khai dùng ULID, không dùng số tự tăng, để không đoán được id của khách khác.
- Có bộ test bắt buộc: tạo 2 tài khoản, gọi mọi API của tài khoản B bằng phiên của tài khoản A, tất cả phải trả về 404.
- Ghi nhật ký truy cập (`audit_logs`) cho mọi lần xem hoặc sửa hồ sơ bệnh nhân.

### 7.3 Bảng dữ liệu

Dùng chung toàn hệ thống (không có `tenant_id`, chỉ đội vận hành sửa):

| Bảng | Trường chính |
| --- | --- |
| `drugs` | tên thương mại, hoạt chất, hàm lượng, dạng (viên / bút tiêm / gel), đơn vị, cảnh báo chung |
| `condition_templates` | mã ICD, tên bệnh, chỉ số theo dõi, phiên bản |
| `template_monitoring` | mẫu, giai đoạn, số ngày, thời điểm đo theo thứ |
| `template_thresholds` | mẫu, chỉ số, ngữ cảnh (trước ăn / sau ăn…), mức đạt / chú ý / đỏ |
| `content_articles` | bài hướng dẫn, dấu hiệu nguy hiểm, câu hỏi gợi ý; gắn với mẫu bệnh |
| `consent_versions` | phiên bản điều khoản, nội dung, ngày hiệu lực |

Theo tài khoản:

| Bảng | Trường chính |
| --- | --- |
| `tenants` | tên, loại (gia đình / phòng khám), gói dịch vụ, trạng thái |
| `users` | điện thoại / email, mật khẩu băm, xác thực 2 lớp (bắt buộc với bác sĩ) |
| `tenant_members` | tenant, user, vai trò cấp tài khoản (owner / member) |
| `invitations` | tenant, người mời, vai trò, bệnh nhân được giao, mã mời, hạn |
| `consents` | user, phiên bản điều khoản, thời điểm, IP |
| `patients` | tenant, họ tên, năm sinh, giới tính, dị ứng, múi giờ |
| `patient_access` | patient, user, vai trò (caregiver / patient / doctor / viewer) |
| `patient_routines` | patient, giờ thức, 3 bữa, giờ ngủ, hiệu lực từ ngày |
| `patient_conditions` | patient, mẫu bệnh, ngày chẩn đoán, mức ưu tiên, ghi chú |
| `patient_thresholds` | patient, chỉ số, ngữ cảnh, các mức, nguồn (mẫu / bác sĩ), người xác nhận, thời điểm xác nhận |
| `documents` | patient, ảnh (đường dẫn trong kho lưu trữ), loại phiếu, ngày, khoa, bác sĩ, phân tích, trùng với |
| `lab_results` | patient, document, chỉ số, giá trị, đơn vị, khoảng tham chiếu, cờ, ngày |
| `prescriptions` | patient, document, bác sĩ kê, ngày kê, từ ngày, đến ngày, trạng thái (đang dùng / đã đóng) |
| `prescription_items` | prescription, drug, liều, cách dùng (JSON, mục 5.4), số lượng kê, số lượng đã mua, dùng lâu dài |
| `schedule_items` | patient, prescription_item (nếu có), giờ, loại (thuốc / insulin / đo / vận động / bữa ăn), quy tắc ngày, từ ngày, đến ngày |
| `monitoring_plans` | patient, giai đoạn, từ ngày, đến ngày, thời điểm đo theo thứ |
| `logs` | patient, ngày, schedule_item, đã làm, giờ làm, người ghi |
| `readings` | patient, loại, thời điểm đo, giá trị, đánh giá, người ghi |
| `events` | patient, ngày, hạn, loại (khám / xét nghiệm / mua thuốc / tiêm phòng), trạng thái |
| `alerts` | patient, reading / event, mức, nội dung, đã gửi cho ai, đã xem lúc nào |
| `questions` | patient, bác sĩ, câu hỏi, đã hỏi, câu trả lời, người trả lời |
| `notes` | patient, người viết, nội dung (nhận xét bác sĩ, ghi chú hằng ngày) |
| `push_subscriptions` | user, endpoint, khoá, thiết bị |
| `audit_logs` | tenant, user, hành động, đối tượng, thời điểm, IP |

### 7.4 API

Tất cả nằm dưới `/api/v1`. Tài khoản lấy từ phiên đăng nhập, không bao giờ lấy từ tham số người dùng gửi lên.

| Phương thức | Đường dẫn | Việc |
| --- | --- | --- |
| POST | `/auth/otp`, `/auth/verify`, `/auth/logout` | Đăng nhập bằng OTP |
| POST | `/onboarding` | Tạo tài khoản + bệnh nhân đầu tiên + giờ sinh hoạt trong một bước |
| GET / POST | `/patients` | Danh sách bệnh nhân được phép xem; thêm bệnh nhân |
| GET / PUT | `/patients/{p}` | Hồ sơ bệnh nhân |
| PUT | `/patients/{p}/routine` | Đổi giờ sinh hoạt (lịch tự sinh lại từ hôm sau) |
| GET / POST / DELETE | `/patients/{p}/conditions` | Gán / bỏ bệnh nền (chép mẫu) |
| GET / PUT | `/patients/{p}/thresholds` | Xem / sửa / xác nhận ngưỡng |
| GET / POST | `/patients/{p}/prescriptions` | Xem đơn; thêm đơn mới |
| POST | `/patients/{p}/prescriptions/preview` | Trả về lịch mẫu từ đơn nháp, chưa lưu |
| POST | `/patients/{p}/prescriptions/{rx}/close` | Đóng đơn cũ |
| GET | `/patients/{p}/day/{date}` | Lịch trong ngày đã ghép với trạng thái |
| PUT | `/patients/{p}/logs/{date}/{item}` | Tích / bỏ tích |
| POST, GET | `/patients/{p}/readings` | Ghi chỉ số (trả về đánh giá + cảnh báo); lấy số liệu biểu đồ, xuất CSV |
| GET / POST / PUT | `/patients/{p}/events` | Mốc khám, xét nghiệm, mua thuốc |
| POST / GET | `/patients/{p}/documents` | Tải ảnh phiếu; danh sách |
| GET / POST / PUT | `/patients/{p}/questions`, `/patients/{p}/notes` | Câu hỏi bác sĩ, ghi chú |
| GET / POST / DELETE | `/members`, `/invitations` | Thành viên, lời mời, vai trò |
| GET | `/catalog/drugs?q=` , `/catalog/templates` | Tra danh mục thuốc, mẫu bệnh |
| GET | `/doctor/patients` | (Giai đoạn 4) danh sách bệnh nhân kèm cờ đỏ, % tuân thủ |
| POST | `/me/export`, `/me/delete` | Xuất toàn bộ dữ liệu; yêu cầu xoá tài khoản |
| POST | `/push/subscribe` | Đăng ký thông báo đẩy |

### 7.5 Cấu trúc thư mục

```
suckhoe/
├── prototype/                 # bản mẫu hiện tại: index.html, api.php, assets/ (chỉ để tham chiếu)
├── PLAN.md
├── backend/                   # Laravel
│   ├── app/Models/            # Tenant, Patient, Prescription, ... (dùng trait BelongsToTenant)
│   ├── app/Policies/          # PatientPolicy, ...
│   ├── app/Services/Schedule/ # bộ sinh lịch: UsageRuleParser, ScheduleGenerator
│   ├── app/Services/Alerts/   # đánh giá chỉ số theo patient_thresholds
│   ├── database/migrations/
│   ├── database/seeders/      # DrugSeeder, TemplateSeeder, DemoPatientBaDSeeder
│   ├── routes/api.php
│   └── tests/Feature/         # TenantIsolationTest, ScheduleGeneratorBaDTest, ...
└── frontend/                  # PWA
    ├── index.html, manifest.webmanifest, sw.js
    ├── css/app.css            # token từ mục 6.3
    └── js/                    # api.js, store.js, views/*.js, chart.js, i18n/vi.json
```

---

## 8. Pháp lý và bảo mật

Cần hoàn thành phần này trước khi mở cho khách ngoài gia đình bà D.

| Việc | Căn cứ | Việc cụ thể |
| --- | --- | --- |
| Xin đồng ý xử lý dữ liệu sức khỏe | Nghị định 13/2023 (dữ liệu cá nhân nhạy cảm) | Màn hình đồng ý riêng, lưu phiên bản; người dùng rút lại được |
| Hồ sơ đánh giá tác động xử lý dữ liệu | Nghị định 13/2023 | Lập hồ sơ, lưu sẵn để cơ quan chức năng kiểm tra |
| Quyền của người dùng | Nghị định 13/2023 | Xem, xuất, sửa, xoá dữ liệu; xử lý yêu cầu xoá trong thời hạn công bố |
| Không trở thành thiết bị y tế | Nghị định 98/2021 về thiết bị y tế | Giữ vai trò sổ theo dõi: không gợi ý liều, không chẩn đoán. Hỏi luật sư trước khi thêm tính năng nào có tính "khuyến nghị" |
| Điều khoản sử dụng, miễn trừ trách nhiệm | — | Ghi rõ app không thay thế bác sĩ; cảnh báo đỏ luôn kèm hướng dẫn gọi 115 / đi khám |
| Bảo mật kỹ thuật | — | HTTPS; mật khẩu băm; 2 lớp cho bác sĩ và chủ phòng khám; mã hoá ảnh; giới hạn số lần gọi API; sao lưu và thử khôi phục mỗi tháng; kiểm tra bảo mật trước khi ra mắt |
| Dữ liệu không thu thập | — | Không lưu số CCCD, số thẻ BHYT, mật khẩu tra cứu in trên phiếu. Khi tải ảnh phiếu, nhắc người dùng che các số này |

Các căn cứ pháp lý trên chỉ để định hướng. Cần luật sư xác nhận trước khi ra mắt.

---

## 9. Lộ trình phát triển

Bắt đầu 28/09/2026. Gia đình bà D. dùng bản mẫu trong lúc chờ phần mềm thật, rồi chuyển sang ngay khi giai đoạn 2 xong.

| Giai đoạn | Thời gian | Nội dung | Tiêu chí hoàn thành |
| --- | --- | --- | --- |
| 0. Bản mẫu | Đang dùng | Gia đình bà D. dùng [index.html](index.html) hằng ngày | Có số liệu đường huyết mỗi ngày |
| 1. Nền tảng nhiều khách | 28/09 – 25/10 (4 tuần) | Laravel, schema mục 7.3, đăng nhập OTP, tài khoản, mời thành viên, vai trò, nhật ký truy cập, seed danh mục + dữ liệu bà D. | Test tách dữ liệu 2 tài khoản đạt 100%; dữ liệu bà D. từ bản mẫu đã chuyển sang |
| 2. Lịch và theo dõi | 26/10 – 15/11 (3 tuần) | Bộ sinh lịch, màn Hôm nay / Lịch / Ghi chỉ số, ngưỡng theo từng bệnh nhân, cảnh báo, PWA, thông báo đẩy | Test bộ sinh lịch với 3 đơn bà D. ra đúng mục 4.1; gia đình bà D. chuyển hẳn sang dùng |
| 3. Khách mới tự đăng ký | 16/11 – 06/12 (3 tuần) | Luồng đăng ký 7 bước, nhập / đóng đơn, danh mục thuốc, 5 mẫu bệnh, hồ sơ tải ảnh, câu hỏi bác sĩ | 3 gia đình thử nghiệm tự đăng ký và có lịch trong ≤ 15 phút, không cần hỗ trợ |
| 4. Bác sĩ và phòng khám | 07/12 – 03/01/2027 (4 tuần) | Tài khoản phòng khám, giao bệnh nhân cho bác sĩ, trang bác sĩ, xác nhận ngưỡng, báo cáo PDF | 1 phòng khám thử nghiệm theo dõi ≥ 10 bệnh nhân trong 2 tuần |
| 5. AI đọc đơn và Zalo | 04/01 – 31/01/2027 (4 tuần) | Chụp đơn → AI đề xuất → duyệt; Zalo ZNS / SMS cho cảnh báo đỏ | Đọc đúng ≥ 95% dòng thuốc trên 20 đơn mẫu |
| Song song: pháp lý và hạ tầng | 28/09 – 06/12 | Mục 8, VPS tại Việt Nam, sao lưu, giám sát | Xong trước khi giai đoạn 3 mở cho khách ngoài |

Điều kiện chặn: **không mở cho khách ngoài gia đình bà D.** khi giai đoạn 1 và phần pháp lý chưa xong.

**Giai đoạn 6 — Hoàn thiện và sửa lỗi (thêm ngày 26/09/2026 sau rà soát):** backend giai đoạn 1–5 đã có, nhưng giao diện giai đoạn 2–5 còn thiếu và có 4 lỗi bảo mật chặn. Checklist và prompt nằm trong [REVIEW.md](REVIEW.md). Chưa cho gia đình bà D. chuyển sang bản mới khi giai đoạn 6 chưa xong.

### 9.1 Việc cần làm ở giai đoạn 1

- [x] Tạo dự án Laravel trong `backend/`; chuyển bản mẫu vào `prototype/` (sửa đường dẫn `assets/img/`)
- [x] Migration cho toàn bộ bảng ở mục 7.3, id dạng ULID
- [x] Trait `BelongsToTenant` + global scope; middleware gắn tenant hiện tại từ phiên
- [x] Đăng nhập OTP (Sanctum), xác thực 2 lớp cho vai trò bác sĩ
- [x] `PatientPolicy` theo `patient_access`; áp cho mọi route có `{patient}`
- [x] Lời mời: tạo mã, hạn 7 ngày, chấp nhận thì tạo `patient_access`
- [x] Ghi `audit_logs` qua middleware cho mọi route bệnh nhân
- [x] `DrugSeeder`: 12 thuốc của bà D. + các thuốc tiểu đường, huyết áp, mỡ máu thường gặp
- [x] `TemplateSeeder`: 5 mẫu bệnh ở mục 5.5 với ngưỡng, lịch đo, bài hướng dẫn (lấy từ 3 tờ hướng dẫn của bệnh viện)
- [x] `DemoPatientBaDSeeder`: chuyển `MEDS`, `EVENTS`, `CONDS`, `LABS`, `RECORDS`, `QA` trong bản mẫu vào bảng
- [x] Lệnh chuyển `data/log.json` của bản mẫu sang `logs` và `readings`
- [x] `TenantIsolationTest`: 2 tài khoản, mọi API chéo trả về 404
- [x] Màn hình đăng nhập và chọn bệnh nhân theo khung mục 6

### 9.2 Việc cần làm ở giai đoạn 2 — Lịch và theo dõi

- [x] `UsageRuleParser`: đọc JSON cách dùng (mục 5.4), báo lỗi rõ khi thiếu mốc hoặc sai đơn vị
- [x] `ScheduleGenerator`: từ `prescription_items` + `patient_routines` sinh `schedule_items` có khoảng ngày; gộp các việc trùng giờ
- [x] Sinh lại lịch khi đổi giờ sinh hoạt, thêm đơn hoặc đóng đơn; chỉ áp dụng từ hôm sau, không sửa lịch sử
- [x] Sinh lượt đo từ `monitoring_plans` theo giai đoạn và thứ trong tuần (mục 4.2)
- [x] `ScheduleGeneratorBaDTest`: 3 đơn bà D., giờ sinh hoạt 07:00 / 12:00 / 18:30 / 22:00, so khớp từng dòng với mục 4.1
- [x] Endpoint `/patients/{p}/day/{date}` trả về lịch đã ghép trạng thái `logs` và `readings`
- [x] `ReadingEvaluator`: đánh giá theo `patient_thresholds` (không còn ngưỡng viết cứng); ngưỡng đỏ thì tạo `alerts`
- [x] Màn Hôm nay, Lịch, Ghi chỉ số chuyển từ bản mẫu sang gọi API; giữ nguyên giao diện
- [x] Biểu đồ đường huyết và huyết áp đọc từ API; xuất CSV phía server
- [x] `manifest.webmanifest`, `sw.js`: cache giao diện, hàng đợi ghi khi mất mạng, đồng bộ lại khi có mạng
- [x] Web Push: khoá VAPID, job gửi nhắc trước giờ 5 phút, nhắc lại sau 30 phút nếu chưa tích; tắt được theo từng người
- [x] Nhắc mốc (khám, xét nghiệm, mua thuốc) trước 3 ngày và trước 1 ngày
- [x] Tính ngày hết thuốc từ số lượng đã mua; tạo mốc "mua thêm" trước 5 ngày
- [ ] Chuyển gia đình bà D. sang bản mới; đối chiếu 1 tuần số liệu với bản mẫu

### 9.3 Việc cần làm ở giai đoạn 3 — Khách mới tự đăng ký (API ✓, Giao diện ✓)

- [x] Luồng đăng ký 7 bước (mục 5.3), lưu nháp giữa chừng, quay lại được bước trước
- [x] Màn đồng ý điều khoản; lưu `consents` theo `consent_versions`
- [x] Tìm thuốc trong danh mục (tìm theo tên thương mại và hoạt chất, không phân biệt dấu)
- [x] Bộ chọn cách dùng bằng nút bấm, sinh JSON mục 5.4; cho nhập tay nếu không có trong danh sách
- [x] Endpoint xem trước lịch từ đơn nháp; màn xem trước cho sửa giờ từng dòng
- [x] Nhập đơn mới / đóng đơn cũ; khi đóng thì hỏi thuốc nào tiếp tục sang đơn mới
- [x] Gán bệnh nền → chép mẫu thành `patient_thresholds`, `monitoring_plans`, câu hỏi gợi ý
- [x] Màn xác nhận ngưỡng, hiện nhãn đã / chưa xác nhận; lưu lịch sử thay đổi
- [x] Tải ảnh phiếu lên kho lưu trữ có mã hoá; nhắc che số CCCD / BHYT; chọn loại phiếu
- [x] Nhập bảng xét nghiệm bằng tay, tự gắn cờ theo khoảng tham chiếu
- [x] Câu hỏi cho bác sĩ: thêm, đánh dấu đã hỏi, ghi câu trả lời
- [x] Cài đặt: giờ sinh hoạt, cỡ chữ lớn, thông báo, thành viên, xuất dữ liệu, yêu cầu xoá
- [ ] Mở cho 3 gia đình thử nghiệm, đo thời gian đăng ký, ghi lại chỗ bị kẹt

### 9.4 Việc cần làm ở giai đoạn 4 — Bác sĩ và phòng khám (API ✓, Giao diện ✓)

- [x] Loại tài khoản phòng khám; giao bệnh nhân cho từng bác sĩ
- [x] Bắt buộc xác thực 2 lớp cho vai trò bác sĩ
- [x] Trang danh sách bệnh nhân: sắp theo cờ đỏ 7 ngày, % tuân thủ, chỉ số gần nhất, ngày tái khám
- [x] Hồ sơ bệnh nhân bố cục 2 cột trên máy tính; biểu đồ 30 / 90 ngày
- [x] Bác sĩ xác nhận ngưỡng, sửa lịch đo, ghi nhận xét (`notes`); gia đình thấy nhận xét trong app
- [x] Bác sĩ trả lời câu hỏi trong luồng chat
- [x] Báo cáo PDF một trang cho buổi tái khám
- [ ] Thử với 1 phòng khám, ≥ 10 bệnh nhân, 2 tuần

### 9.5 Việc cần làm ở giai đoạn 5 — AI đọc đơn và Zalo (API ✓, Giao diện ✓)

- [x] Gửi ảnh đơn cho mô hình AI, nhận về danh sách dòng thuốc theo JSON cố định (tên, hàm lượng, số lượng, cách dùng)
- [x] Khớp tên thuốc với danh mục; dòng không khớp đánh dấu để người dùng chọn
- [x] Màn duyệt: ảnh gốc bên cạnh, từng dòng phải bấm xác nhận mới lưu
- [x] Bộ 20 đơn mẫu có đáp án, chạy đo độ chính xác mỗi khi đổi prompt hoặc mô hình
- [x] Đăng ký Zalo OA doanh nghiệp, mẫu tin ZNS cho cảnh báo đỏ và nhắc tái khám
- [x] SMS dự phòng khi người nhận không có Zalo

### 9.6 Quy ước làm việc cho mọi giai đoạn

- Mỗi việc trong checklist là một nhánh git và một pull request nhỏ.
- Mọi API có test tính năng; mọi route bệnh nhân nằm trong `TenantIsolationTest`.
- Không hardcode dữ liệu bệnh nhân trong code; dữ liệu mẫu chỉ nằm trong seeder.
- Không viết code tính, đổi hay gợi ý liều thuốc.
- Chữ hiển thị đặt trong file ngôn ngữ; tiếng Việt có dấu đầy đủ.
- Cuối mỗi giai đoạn: chạy toàn bộ test, cập nhật tick trong PLAN.md, ghi thay đổi vào `CHANGELOG.md`.

---

## 10. Rủi ro

| Rủi ro | Hậu quả | Cách giảm |
| --- | --- | --- |
| Lộ dữ liệu giữa các khách hàng | Mất uy tín, vi phạm Nghị định 13/2023 | Global scope bắt buộc, Policy mọi route, id ULID, test tách dữ liệu chạy mỗi lần đẩy code |
| Người dùng hiểu gợi ý của app là chỉ định y khoa | Tự chỉnh liều insulin, hạ đường huyết | Không có chức năng đổi liều; mọi màn thuốc ghi "theo đơn bác sĩ"; ngưỡng mặc định có nhãn "chưa xác nhận" |
| Ngưỡng mặc định không hợp với một bệnh nhân (người già, suy thận…) | Cảnh báo sai, bỏ sót nguy hiểm | Bắt buộc xác nhận ngưỡng ở bước đăng ký; khuyến khích mời bác sĩ vào xác nhận |
| Bộ sinh lịch hiểu sai cách dùng | Uống sai giờ | Không tự đoán khi không hiểu; luôn cho xem trước và xác nhận; test với nhiều đơn thật |
| App bị xếp vào thiết bị y tế | Phải xin phép, chậm ra mắt | Giữ phạm vi sổ theo dõi; hỏi luật sư trước giai đoạn 3 |
| Thông báo đẩy trên web không ổn định (iOS, tiết kiệm pin) | Bỏ lỡ giờ tiêm | Khuyến nghị đặt thêm báo thức; giai đoạn 5 có Zalo / SMS; cân nhắc làm app gốc nếu tỷ lệ lỡ cao |
| AI đọc sai đơn thuốc | Lịch sai | Chỉ là đề xuất, duyệt từng dòng, hiện ảnh gốc bên cạnh |
| Khối lượng hỗ trợ khách tăng nhanh | Đội nhỏ không xử lý kịp | Luồng đăng ký tự phục vụ, bài hướng dẫn trong app, mở dần từng nhóm khách |

---

## 11. Câu hỏi mở

Về sản phẩm:
- [ ] Nhắm khách nào trước: gia đình hay phòng khám? (Ảnh hưởng thứ tự giai đoạn 3 và 4)
- [ ] Có thu phí không, từ khi nào? Gói miễn phí giới hạn ở đâu?
- [ ] Tên sản phẩm và tên miền?
- [ ] Đơn vị pháp nhân nào đứng tên sản phẩm và ký điều khoản với khách?
- [ ] Đội phát triển gồm mấy người? (Lộ trình trên tính cho 1–2 lập trình viên)
- [ ] Ai là người có chuyên môn y khoa duyệt nội dung mẫu bệnh và ngưỡng mặc định?

Về ca bệnh mẫu bà D.:
- [ ] Tên các thuốc huyết áp và mỡ máu bà đang dùng là gì?
- [ ] Bác sĩ nội tiết có đồng ý lịch đo 3 giai đoạn và ngưỡng ở mục 4.3 không?
- [ ] Insulin trưa 6 UI ghi ngày 26/9 là chỉ riêng hôm khám, đúng không?
- [ ] Người nhà dùng chính là ai, dùng Android hay iPhone?
