import React, { useState, useEffect } from 'react';
import { getSlotDetail, getBookingDetail } from '../services/api';

const SlotDetail = ({ slot, onClose, onSelectBooking }) => {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const isBookingMode = slot.mode === 'booking';
  const bookingId = slot.bookingId;

  // Simple calculation for child/adult breakdown
  const parseBooklyCustomFields = (fieldsJson, totalGuests) => {
    const fields = typeof fieldsJson === 'string' ? JSON.parse(fieldsJson) : fieldsJson;
    if (!fields || !Array.isArray(fields)) return { adults: totalGuests, children: 0 };
    
    // ID mapping for child count
    const childField = fields.find(f => f.id === 3185 || f.id === "3185");
    const children = childField ? parseInt(childField.value || 0) : 0;
    const adults = Math.max(0, totalGuests - children);
    
    return { adults, children };
  };

  // Use the specific fetch booking detail or slot summary based on mode
  useEffect(() => {
    const loadData = async () => {
      setLoading(true);
      setError(null);
      try {
        let response;
        if (isBookingMode) {
          response = await getBookingDetail(bookingId);
          console.log("SlotDetail Trace: Booking Detail Response:", response);
        } else {
          // Dashboard groups slot by 'ids' (plural) to support multiple simultaneous staff appointments
          response = await getSlotDetail(slot.ids);
          console.log("SlotDetail Trace: Slot Detail Response:", response);
          console.log("SlotDetail Trace: Reservations Count:", response.length);
        }
        setData(response);
      } catch (err) {
        console.error("Detail Fetch Error:", err);
        setError("Could not load detail. Please check the server logs.");
      } finally {
        setLoading(false);
      }
    };
    loadData();
  }, [slot.appointment_id, bookingId, isBookingMode]);

  // Handle empty state explicitly
  if (error) {
    return (
      <div className="slot-detail-backdrop" onClick={onClose}>
        <aside className="slot-detail-pane error-pane" onClick={e => e.stopPropagation()}>
          <header>
            <h3 style={{color: '#c0392b'}}>⚠️ Error Fetching Data</h3>
            <button className="btn-close" onClick={onClose}>&times;</button>
          </header>
          <div className="slot-detail-content">
            <p className="error-message">{error}</p>
            <p className="error-hint">This usually happens if the requested booking token or appointment ID is invalid in the database.</p>
            <button 
              className="btn-search" 
              style={{marginTop: '2rem', width: '100%', background: 'var(--primary)', color: 'white', border: 'none', padding: '1rem', borderRadius: '12px'}}
              onClick={() => window.location.reload()}
            >
              Try Reloading Dashboard
            </button>
          </div>
        </aside>
      </div>
    );
  }

  // Render Skeleton/Loading
  if (loading || !data) {
    return (
      <div className="slot-detail-backdrop" onClick={onClose}>
        <aside className="slot-detail-pane" onClick={e => e.stopPropagation()}>
          <header>
            <h3>Loading Details...</h3>
            <button className="btn-close" onClick={onClose}>&times;</button>
          </header>
          <div className="slot-detail-content">
            <p className="loading-small">Fetching booking information from server...</p>
          </div>
        </aside>
      </div>
    );
  }

  const totals = !isBookingMode && Array.isArray(data) ? data.reduce((acc, b) => {
    const breakdown = parseBooklyCustomFields(b.custom_fields, b.attendees);
    return { 
      adults: acc.adults + breakdown.adults, 
      children: acc.children + breakdown.children 
    };
  }, { adults: 0, children: 0 }) : { adults: 0, children: 0 };

  return (
    <div className="slot-detail-backdrop" onClick={onClose}>
      <aside className="slot-detail-pane" onClick={e => e.stopPropagation()}>
        <header>
          {isBookingMode ? (
            <div className="detail-booking-header">
              <div className="customer-card">
                <div style={{marginBottom: '0.4rem'}}>
                  <span className="guest-ref-badge">REF: #B-{data.reference}</span>
                </div>
                <h2 className="customer-name-big">{data.customer_name || 'N/A'}</h2>
                <div className="contact-links">
                  {data.customer_email && (
                    <a href={`mailto:${data.customer_email}`} className="contact-link">
                      <span className="contact-icon">📧</span>
                      <span>{data.customer_email}</span>
                    </a>
                  )}
                  {data.customer_phone && (
                    <a href={`tel:${data.customer_phone}`} className="contact-link">
                      <span className="contact-icon">📞</span>
                      <span>{data.customer_phone}</span>
                    </a>
                  )}
                </div>
              </div>
            </div>
          ) : (
            <div style={{padding: '2rem 2rem 1rem 2rem'}}>
              <h3 style={{margin: 0, color: 'var(--primary)', fontSize: '1.5rem'}}>{slot.experienceName}</h3>
              <small style={{color: 'var(--muted)', fontWeight: 600}}>
                {new Date(slot.date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })} 
                {' • '} 
                {slot.time}
              </small>
            </div>
          )}
          <button className="btn-close" onClick={onClose}>&times;</button>
        </header>

        <div className="slot-detail-content">
          {isBookingMode ? (
            /* --- BOOKING DETAIL MODE --- */
            <div className="booking-full-view">
              <div className="booking-info-grid">
                <div className="info-box full-width">
                  <span className="info-label">Experience</span>
                  <div className="info-value">{data.experience || 'N/A'}</div>
                </div>
                <div className="info-box">
                  <span className="info-label">Date & Time</span>
                  <div className="info-value">
                    {data.start_date ? new Date(data.start_date).toLocaleDateString('en-GB') : 'N/A'} at {slot.time}
                  </div>
                </div>
                <div className="info-box">
                  <span className="info-label">Guests</span>
                  <div className="info-value">{data.attendees} Total</div>
                  <div className="guest-badges" style={{marginTop: '0.6rem'}}>
                    {parseBooklyCustomFields(data.custom_fields, data.attendees).adults > 0 && 
                      <span className="type-badge adult">Adult x{parseBooklyCustomFields(data.custom_fields, data.attendees).adults}</span>
                    }
                    {parseBooklyCustomFields(data.custom_fields, data.attendees).children > 0 && 
                      <span className="type-badge child">Under 14 x{parseBooklyCustomFields(data.custom_fields, data.attendees).children}</span>
                    }
                  </div>
                </div>
                <div className="info-box full-width">
                  <span className="info-label">Payment</span>
                  <div className="payment-status-row">
                    <span className={`badge-payment ${data.payment_method?.toLowerCase() || 'cash'}`}>
                      {data.payment_method || 'Method N/A'}
                    </span>
                    <span className={`badge-payment ${data.payment_status?.toLowerCase() || 'pending'}`}>
                      {data.payment_status || 'Pending'}
                    </span>
                  </div>
                  <div className="info-value" style={{marginTop: '0.6rem'}}>
                    £{parseFloat(data.payment_amount || 0).toFixed(2)}
                  </div>
                </div>

                {data.resolved_extras && data.resolved_extras.length > 0 && (
                  <div className="info-box full-width" style={{ borderLeft: '4px solid var(--primary)', background: 'rgba(52, 152, 219, 0.05)' }}>
                    <span className="info-label">Extras</span>
                    <div className="extras-list" style={{ marginTop: '0.6rem' }}>
                      {data.resolved_extras.map((extra, eIdx) => (
                        <div key={eIdx} className="extra-item" style={{ fontWeight: 700, color: 'var(--primary)', fontSize: '1.1rem', marginBottom: '0.4rem' }}>
                          • {extra.title} <span style={{ color: 'var(--muted)', fontWeight: 600 }}>x{extra.quantity}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </div>

              {data.internal_note && (
                <div className="internal-note-box">
                  <h5>Internal Note from Bookly</h5>
                  <p>{data.internal_note}</p>
                </div>
              )}

              {data.custom_fields && Array.isArray(data.custom_fields) && data.custom_fields.length > 0 && (
                <div className="detail-section" style={{marginTop: '2rem'}}>
                  <header className="section-header">
                    <h4>Booking Answers</h4>
                  </header>
                  <div className="guest-notes">
                    {data.custom_fields.map((field, nIdx) => {
                      const val = Array.isArray(field.value) ? field.value.join(', ') : field.value;
                      if (val === null || val === undefined || val.toString().trim() === '') return null;
                      
                      const hasLabel = field.label && field.label.trim() !== '';
                      
                      return (
                        <div key={nIdx} className="note-item" style={{marginBottom: '0.8rem'}}>
                          {hasLabel ? (
                            <>
                              <strong style={{color: 'var(--primary)'}}>{field.label}:</strong> {val}
                            </>
                          ) : (
                            <span>{val}</span>
                          )}
                        </div>
                      );
                    })}
                  </div>
                </div>
              )}

              <footer className="created-at-footer">
                Booking created on {new Date(data.created_at).toLocaleString('en-GB')}
              </footer>
            </div>
          ) : (
            /* --- SLOT AGGREGATE MODE --- */
            <>
              <div className="slot-meta-triple" style={{padding: '0 2rem'}}>
                <div className="meta-card">
                  <span className="label">Booked</span>
                  <span className="value">{slot.attendees}</span>
                </div>
                <div className="meta-card color-blue">
                  <span className="label">Adults</span>
                  <span className="value">{totals.adults}</span>
                </div>
                <div className="meta-card color-green">
                  <span className="label">Under 14</span>
                  <span className="value">{totals.children}</span>
                </div>
              </div>

              <div className="detail-section" style={{padding: '0 2rem 2rem 2rem'}}>
                <header className="section-header">
                  <h4>Guest List</h4>
                  <span className="badge-count">{data.length} Reservations</span>
                </header>

                {data.length === 0 ? (
                  <p className="no-bookings">No guests in this slot.</p>
                ) : (
                  data.map((b, idx) => {
                    console.log("SlotDetail Render: Mapping Guest Row:", b);
                    const breakdown = parseBooklyCustomFields(b.custom_fields, b.attendees);
                    return (
                      <div 
                        key={`${b.reference}-${idx}`} 
                        className="guest-card clickable" 
                        onClick={() => onSelectBooking(b.booking_token, {})}
                        style={{cursor: 'pointer'}}
                      >
                        <div className="guest-card-main">
                          <div className="guest-info">
                            <div className="guest-name-row">
                              <strong>{b.customer_name || 'Anonymous'}</strong>
                              <span className="guest-ref">#B-{b.reference}</span>
                            </div>
                            
                            <div className="guest-badges">
                              {breakdown.adults > 0 && <span className="type-badge adult">Adult x{breakdown.adults}</span>}
                              {breakdown.children > 0 && <span className="type-badge child">Under 14 x{breakdown.children}</span>}
                            </div>

                            {/* Restore Custom Fields Rendering in List Mode */}
                            {Array.isArray(b.custom_fields) && b.custom_fields.length > 0 && (
                              <div className="guest-notes" style={{borderTop: '1px dashed #e2e8f0', marginTop: '0.6rem', paddingTop: '0.6rem'}}>
                                {b.custom_fields.map((field, fIdx) => {
                                  const val = Array.isArray(field.value) ? field.value.join(', ') : field.value;
                                  if (val === null || val === undefined || val.toString().trim() === '') return null;
                                  
                                  const hasLabel = field.label && field.label.trim() !== '';

                                  return (
                                    <div key={fIdx} className="note-item" style={{fontSize: '0.85rem'}}>
                                      {hasLabel ? (
                                        <><strong>{field.label}:</strong> {val}</>
                                      ) : (
                                        <span>{val}</span>
                                      )}
                                    </div>
                                  );
                                })}
                              </div>
                            )}

                            {/* Internal Note Fallback Mapping */}
                            {b.internal_note && (
                              <div className="note-item" style={{fontSize: '0.85rem', color: '#854d0e', fontStyle: 'italic', marginTop: '0.4rem'}}>
                                Note: {b.internal_note}
                              </div>
                            )}

                            {/* Generic Extras implementation */}
                            {b.resolved_extras && b.resolved_extras.length > 0 && (
                              <div className="guest-extras" style={{ marginTop: '0.8rem', padding: '0.8rem', borderRadius: '8px', background: 'rgba(52, 152, 219, 0.08)', border: '1px solid rgba(52, 152, 219, 0.2)' }}>
                                <div style={{ fontSize: '0.75rem', fontWeight: 800, textTransform: 'uppercase', color: 'var(--primary)', marginBottom: '0.4rem', letterSpacing: '0.05em' }}>
                                  Extras
                                </div>
                                <div className="extras-list">
                                  {b.resolved_extras.map((extra, eIdx) => (
                                    <div key={eIdx} style={{ fontSize: '0.9rem', fontWeight: 700, color: '#2c3e50' }}>
                                      {extra.title} <span style={{ color: 'var(--primary)' }}>x{extra.quantity}</span>
                                    </div>
                                  ))}
                                </div>
                              </div>
                            )}
                          </div>
                          <div className="guest-total">
                            {b.attendees}
                          </div>
                        </div>
                      </div>
                    );
                  })
                )}
              </div>
            </>
          )}
        </div>
      </aside>
    </div>
  );
};

export default SlotDetail;
