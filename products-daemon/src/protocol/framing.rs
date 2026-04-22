//! Length-prefixed JSON framing over Unix sockets.
//!
//! Frame layout: `[u32 BE length][JSON bytes]`. Max frame size is 16 MiB — a deliberately
//! generous ceiling that still catches runaway PHP clients.
//!
//! The PHP side (`Eshop\Services\ProductsCache\RustDaemonClient`) writes/reads the same
//! layout via `pack('N', strlen($json))`.

use tokio::io::{AsyncReadExt, AsyncWriteExt};

use crate::error::ProtocolError;

pub const MAX_FRAME_BYTES: usize = 16 * 1024 * 1024;

/// Write a single length-prefixed frame.
pub async fn write_frame<W: AsyncWriteExt + Unpin>(mut writer: W, payload: &[u8]) -> Result<(), ProtocolError> {
	if payload.len() > MAX_FRAME_BYTES {
		return Err(ProtocolError::FrameTooLarge {
			size: payload.len(),
			limit: MAX_FRAME_BYTES,
		});
	}
	let len = u32::try_from(payload.len()).map_err(|_| ProtocolError::FrameTooLarge {
		size: payload.len(),
		limit: MAX_FRAME_BYTES,
	})?;
	writer
		.write_all(&len.to_be_bytes())
		.await
		.map_err(|e| ProtocolError::Framing(format!("write header: {e}")))?;
	writer
		.write_all(payload)
		.await
		.map_err(|e| ProtocolError::Framing(format!("write payload: {e}")))?;
	writer
		.flush()
		.await
		.map_err(|e| ProtocolError::Framing(format!("flush: {e}")))?;
	Ok(())
}

/// Read a single length-prefixed frame. Fails with `FrameTooLarge` if the header advertises
/// a payload bigger than `MAX_FRAME_BYTES`, before any allocation happens.
pub async fn read_frame<R: AsyncReadExt + Unpin>(mut reader: R) -> Result<Vec<u8>, ProtocolError> {
	let mut header = [0u8; 4];
	reader
		.read_exact(&mut header)
		.await
		.map_err(|e| ProtocolError::Framing(format!("read header: {e}")))?;
	let size = u32::from_be_bytes(header) as usize;
	if size > MAX_FRAME_BYTES {
		return Err(ProtocolError::FrameTooLarge {
			size,
			limit: MAX_FRAME_BYTES,
		});
	}
	let mut buf = vec![0u8; size];
	reader
		.read_exact(&mut buf)
		.await
		.map_err(|e| ProtocolError::Framing(format!("read payload: {e}")))?;
	Ok(buf)
}

#[cfg(test)]
mod tests {
	use super::*;
	use tokio::io::duplex;

	#[tokio::test]
	async fn framing_round_trips_small_payload() {
		let (a, b) = duplex(4096);
		let (_ar, mut aw) = tokio::io::split(a);
		let (mut br, _bw) = tokio::io::split(b);

		let payload = b"{\"method\":\"ping\"}";
		tokio::try_join!(write_frame(&mut aw, payload), async {
			let got = read_frame(&mut br).await?;
			assert_eq!(got, payload);
			Ok::<_, ProtocolError>(())
		},)
		.unwrap();
	}

	#[tokio::test]
	async fn framing_rejects_oversized_header() {
		let (mut client_w, server_r) = duplex(32);
		let header = (MAX_FRAME_BYTES as u32 + 1).to_be_bytes();
		client_w.write_all(&header).await.unwrap();

		let err = read_frame(server_r).await.unwrap_err();
		assert!(matches!(err, ProtocolError::FrameTooLarge { .. }));
	}
}
