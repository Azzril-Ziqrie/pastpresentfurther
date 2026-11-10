-- Tambah status 'waiting_verification' (bukti bayar sudah diupload, menunggu admin).
-- Dipakai payment.php, approve-payment.php, dan dashboard admin.
ALTER TABLE `orders`
  MODIFY `payment_status`
  ENUM('pending','waiting_verification','paid','failed','expired','cancelled')
  NOT NULL DEFAULT 'pending';
