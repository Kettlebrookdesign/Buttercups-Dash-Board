import React, { useState, useEffect } from 'react';
import { api } from '../services/api';

const ManualBookingModal = ({ onClose, onBookingSuccess, defaultDate }) => {
  const [date, setDate] = useState(defaultDate || new Date().toISOString().split('T')[0]);
  const [experiences, setExperiences] = useState([]);
  const [selectedExpId, setSelectedExpId] = useState('');
  const [selectedSlotId, setSelectedSlotId] = useState('');
  
  // Customer details
  const [customerName, setCustomerName] = useState('');
  const [customerPhone, setCustomerPhone] = useState('');
  const [customerEmail, setCustomerEmail] = useState('');
  const [adults, setAdults] = useState(1);
  const [under14, setUnder14] = useState(0);
  
  // Selected extras: mapping extraId to quantity
  const [extras, setExtras] = useState({});
  const [internalNote, setInternalNote] = useState('');
  const [paymentMethod, setPaymentMethod] = useState('Pay on arrival');
  const [paymentNote, setPaymentNote] = useState('');
  
  // Status states
  const [loadingOptions, setLoadingOptions] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [errorMsg, setErrorMsg] = useState('');
  const [successData, setSuccessData] = useState(null);

  // Fetch booking options when date changes
  useEffect(() => {
    const fetchOptions = async () => {
      setLoadingOptions(true);
      setErrorMsg('');
      try {
        const data = await api.getManualBookingOptions(date);
        setExperiences(data || []);
        setSelectedExpId('');
        setSelectedSlotId('');
        setExtras({});
      } catch (err) {
        console.error('Failed to load manual booking options:', err);
        setErrorMsg('Failed to load availability options.');
      }
      setLoadingOptions(false);
    };
    fetchOptions();
  }, [date]);

  // Reset slot & extras when experience changes
  useEffect(() => {
    setSelectedSlotId('');
    setExtras({});
  }, [selectedExpId]);

  const selectedExp = experiences.find(e => String(e.id) === String(selectedExpId));
  const selectedSlot = selectedExp?.slots?.find(s => String(s.appointment_id) === String(selectedSlotId));

  // Handle extra quantity change
  const handleExtraQtyChange = (extraId, qty) => {
    setExtras(prev => ({
      ...prev,
      [extraId]: qty
    }));
  };

  // Capacity validation in UI
  const requestedPeople = Number(adults) + Number(under14);
  const remainingSpaces = selectedSlot ? selectedSlot.remaining_spaces : 0;
  const isOverCapacity = selectedSlot && (requestedPeople > remainingSpaces);

  // Calculate pricing summary
  const basePrice = selectedExp ? selectedExp.price : 0;
  const extrasTotal = selectedExp?.extras?.reduce((acc, ext) => {
    const qty = extras[ext.id] || 0;
    return acc + (ext.price * qty);
  }, 0) || 0;
  const totalPrice = (basePrice * requestedPeople) + extrasTotal;

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!selectedSlotId) {
      setErrorMsg('Please select a time slot.');
      return;
    }
    if (!customerName.trim()) {
      setErrorMsg('Customer name is required.');
      return;
    }
    if (!customerPhone.trim()) {
      setErrorMsg('Customer phone is required.');
      return;
    }
    if (isOverCapacity) {
      setErrorMsg('Selected guests exceed remaining spaces in this slot.');
      return;
    }
    if (paymentMethod === 'Already paid / Voucher' && !paymentNote.trim()) {
      setErrorMsg('Voucher/reference note is required.');
      return;
    }

    setSubmitting(true);
    setErrorMsg('');
    
    const payload = {
      appointment_id: Number(selectedSlotId),
      customer_name: customerName,
      customer_phone: customerPhone,
      customer_email: customerEmail,
      adults: Number(adults),
      under_14: Number(under14),
      extras: extras,
      internal_note: internalNote,
      payment_method: paymentMethod,
      payment_note: paymentNote
    };

    try {
      const res = await api.createManualBooking(payload);
      if (res.code || res.message) {
        setErrorMsg(res.message || 'Server error occurred.');
      } else if (res.success) {
        setSuccessData(res);
      } else {
        setErrorMsg('An unexpected error occurred.');
      }
    } catch (err) {
      console.error('Booking failed:', err);
      setErrorMsg('Failed to process booking.');
    }
    setSubmitting(false);
  };

  return (
    <div className="manual-booking-backdrop">
      <div className="manual-booking-modal">
        <header className="modal-header">
          <h2>Create Manual Booking</h2>
          <button type="button" className="btn-close" onClick={onClose} disabled={submitting}>×</button>
        </header>

        {successData ? (
          <div className="booking-success-view">
            <div className="success-icon">✓</div>
            <h3>Booking Reserved Successfully!</h3>
            <p className="success-ref">Booking Ref: <strong>#B-{successData.booking_id}</strong></p>
            <p className="success-ref">Unique Token: <strong>{successData.token}</strong></p>
            <p className="success-price">Total Price: <strong>£{successData.total_price.toFixed(2)}</strong></p>

            {paymentMethod === 'Pay by card link' && successData.payment_link_status === 'not_configured' && (
              <div className="card-link-box not-configured" style={{ background: '#fffbeb', border: '1px solid #fde68a', color: '#b45309' }}>
                <span className="card-link-label" style={{ color: '#b45309' }}>Payment Link Status:</span>
                <p className="card-link-message" style={{ margin: '0.2rem 0 0 0', fontSize: '0.9rem', fontWeight: 600 }}>
                  ⚠️ {successData.payment_link_message || 'Stripe payment links are not connected yet.'}
                </p>
              </div>
            )}

            <button 
              type="button"
              className="btn-primary success-done-btn" 
              onClick={() => {
                onBookingSuccess();
                onClose();
              }}
            >
              Done & Refresh Dashboard
            </button>
          </div>
        ) : (
          <form className="modal-form" onSubmit={handleSubmit}>
            <div className="modal-form-content">
              {errorMsg && <div className="modal-error-alert">{errorMsg}</div>}

              {/* Step 1: Scheduling Details */}
              <fieldset className="form-fieldset">
                <legend>Scheduling Details</legend>
                <div className="form-grid">
                  <div className="form-group">
                    <label htmlFor="mb-date">Date</label>
                    <input 
                      id="mb-date"
                      type="date" 
                      value={date} 
                      onChange={(e) => setDate(e.target.value)} 
                      className="form-control"
                      required
                    />
                  </div>

                  <div className="form-group">
                    <label htmlFor="mb-experience">Experience / Service</label>
                    <select 
                      id="mb-experience"
                      value={selectedExpId} 
                      onChange={(e) => setSelectedExpId(e.target.value)}
                      className="form-control"
                      disabled={loadingOptions || experiences.length === 0}
                      required
                    >
                      <option value="">-- Select Experience --</option>
                      {experiences.map(exp => (
                        <option key={exp.id} value={exp.id}>
                          {exp.title} (from £{exp.price.toFixed(2)})
                        </option>
                      ))}
                    </select>
                  </div>
                </div>

                {selectedExpId && (
                  <div className="form-group margin-top-sm">
                    <label htmlFor="mb-timeslot">Time Slot</label>
                    <select 
                      id="mb-timeslot"
                      value={selectedSlotId} 
                      onChange={(e) => setSelectedSlotId(e.target.value)}
                      className="form-control"
                      required
                    >
                      <option value="">-- Select Time Slot --</option>
                      {selectedExp?.slots?.map(slot => (
                        <option 
                          key={slot.appointment_id} 
                          value={slot.appointment_id}
                          disabled={slot.remaining_spaces <= 0}
                        >
                          {slot.time} ({slot.remaining_spaces} spaces left of {slot.capacity})
                        </option>
                      ))}
                    </select>
                  </div>
                )}
              </fieldset>

              {/* Step 2: Customer Details */}
              <fieldset className="form-fieldset">
                <legend>Customer Details</legend>
                <div className="form-grid">
                  <div className="form-group">
                    <label htmlFor="mb-name">Customer Name</label>
                    <input 
                      id="mb-name"
                      type="text" 
                      placeholder="John Doe" 
                      value={customerName} 
                      onChange={(e) => setCustomerName(e.target.value)} 
                      className="form-control"
                      required
                    />
                  </div>

                  <div className="form-group">
                    <label htmlFor="mb-phone">Phone Number</label>
                    <input 
                      id="mb-phone"
                      type="tel" 
                      placeholder="07123 456789" 
                      value={customerPhone} 
                      onChange={(e) => setCustomerPhone(e.target.value)} 
                      className="form-control"
                      required
                    />
                  </div>
                </div>

                <div className="form-group margin-top-sm">
                  <label htmlFor="mb-email">Email (Optional)</label>
                  <input 
                    id="mb-email"
                    type="email" 
                    placeholder="john@example.com" 
                    value={customerEmail} 
                    onChange={(e) => setCustomerEmail(e.target.value)} 
                    className="form-control"
                  />
                </div>
              </fieldset>

              {/* Step 3: Attendees Quantity */}
              <fieldset className="form-fieldset">
                <legend>Number of Guests</legend>
                <div className="form-grid">
                  <div className="form-group">
                    <label htmlFor="mb-adults">Adults</label>
                    <input 
                      id="mb-adults"
                      type="number" 
                      min="1" 
                      value={adults} 
                      onChange={(e) => setAdults(Math.max(1, parseInt(e.target.value) || 1))} 
                      className="form-control"
                      required
                    />
                  </div>

                  <div className="form-group">
                    <label htmlFor="mb-under14">Under 14 (Children)</label>
                    <input 
                      id="mb-under14"
                      type="number" 
                      min="0" 
                      value={under14} 
                      onChange={(e) => setUnder14(Math.max(0, parseInt(e.target.value) || 0))} 
                      className="form-control"
                      required
                    />
                  </div>
                </div>

                {isOverCapacity && (
                  <div className="capacity-warning-box">
                    ⚠️ Selected attendees ({requestedPeople}) exceed remaining capacity of {remainingSpaces} spaces!
                  </div>
                )}
              </fieldset>

              {/* Step 4: Service Extras */}
              {selectedExp && selectedExp.extras && selectedExp.extras.length > 0 && (
                <fieldset className="form-fieldset">
                  <legend>Experience Extras</legend>
                  <div className="extras-selector-list">
                    {selectedExp.extras.map(ext => {
                      const currentQty = extras[ext.id] || 0;
                      return (
                        <div key={ext.id} className="extra-selector-row">
                          <div className="extra-details">
                            <span className="extra-title">{ext.title}</span>
                            <span className="extra-price">+£{ext.price.toFixed(2)} each</span>
                          </div>
                          <div className="extra-quantity-control">
                            <button 
                              type="button" 
                              className="qty-btn"
                              onClick={() => handleExtraQtyChange(ext.id, Math.max(ext.min_quantity, currentQty - 1))}
                              disabled={currentQty <= ext.min_quantity}
                            >
                              -
                            </button>
                            <span className="qty-val">{currentQty}</span>
                            <button 
                              type="button" 
                              className="qty-btn"
                              onClick={() => handleExtraQtyChange(ext.id, Math.min(ext.max_quantity, currentQty + 1))}
                              disabled={currentQty >= ext.max_quantity}
                            >
                              +
                            </button>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </fieldset>
              )}

              {/* Step 5: Notes & Payments */}
              <fieldset className="form-fieldset">
                <legend>Notes & Payment</legend>
                
                <div className="form-group">
                  <label htmlFor="mb-note">Internal Notes</label>
                  <textarea 
                    id="mb-note"
                    placeholder="Enter any internal notes or details..." 
                    value={internalNote} 
                    onChange={(e) => setInternalNote(e.target.value)} 
                    className="form-control text-area"
                    rows="3"
                  />
                </div>

                <div className="form-grid margin-top-sm">
                  <div className="form-group">
                    <label htmlFor="mb-payment">Payment Method</label>
                    <select 
                      id="mb-payment"
                      value={paymentMethod} 
                      onChange={(e) => setPaymentMethod(e.target.value)}
                      className="form-control"
                      required
                    >
                      <option value="Pay on arrival">Pay on arrival</option>
                      <option value="Pay by card link">Pay by card link</option>
                      <option value="Already paid / Voucher">Already paid / Voucher</option>
                    </select>
                  </div>

                  {paymentMethod === 'Already paid / Voucher' && (
                    <div className="form-group">
                      <label htmlFor="mb-paynote">Voucher / Reference Note</label>
                      <input 
                        id="mb-paynote"
                        type="text" 
                        placeholder="Voucher code or payment ref..." 
                        value={paymentNote} 
                        onChange={(e) => setPaymentNote(e.target.value)} 
                        className="form-control"
                        required
                      />
                    </div>
                  )}
                </div>
              </fieldset>
            </div>

            {/* Sticky Pricing Summary & Submit */}
            <footer className="modal-footer">
              {selectedSlot && (
                <div className="pricing-summary-box">
                  <span className="price-label">Estimated Total Price:</span>
                  <span className="price-val">£{totalPrice.toFixed(2)}</span>
                </div>
              )}
              
              <div className="footer-actions">
                <button 
                  type="button" 
                  className="btn-secondary" 
                  onClick={onClose} 
                  disabled={submitting}
                >
                  Cancel
                </button>
                <button 
                  type="submit" 
                  className="btn-primary" 
                  disabled={submitting || !selectedSlotId || isOverCapacity}
                >
                  {submitting ? 'Creating Booking...' : 'Create Booking'}
                </button>
              </div>
            </footer>
          </form>
        )}
      </div>
    </div>
  );
};

export default ManualBookingModal;
