/**
 * Bật / tắt nhóm tính năng trên giao diện. Hiện tại app chỉ phục vụ người bệnh tự dùng:
 * tắt cổng bác sĩ, Hỏi bác sĩ, nhiều người bệnh / không gian chăm sóc, mời thành viên, đăng ký 7 bước.
 * Backend vẫn giữ nguyên các API này; đổi `true` để bật lại.
 */
export const FEATURES = {
    doctor: false,
    caregiver: false,
};
