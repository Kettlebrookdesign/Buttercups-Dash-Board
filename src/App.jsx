import React, { useState, useEffect } from 'react';
import Dashboard from './components/Dashboard';
import Search from './components/Search';
import SlotDetail from './components/SlotDetail';
import ManualBookingModal from './components/ManualBookingModal';
import './App.css';

const App = () => {
  const [date, setDate] = useState(new Date().toISOString().split('T')[0]);
  const [activeSlot, setActiveSlot] = useState(null);
  const [showManualBooking, setShowManualBooking] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);

  return (
    <div className="app-container">
      <header className="app-header">
        <h1>Buttercups Staff Dashboard</h1>
        <div className="header-actions">
          <button 
            className="btn-manual-booking" 
            onClick={() => setShowManualBooking(true)}
          >
            ＋ Manual Booking
          </button>
          <Search onSelect={(booking) => {
            setActiveSlot({
              mode: 'booking',
              bookingId: booking.booking_token,
              time: new Date(booking.start_date).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
              experienceName: booking.experience,
              date: booking.start_date.split(' ')[0]
            });
          }} />
        </div>
      </header>

      <main className="app-content">
        <Dashboard 
          key={`${date}-${refreshKey}`}
          selectedDate={date} 
          onDateSelect={setDate} 
          onSlotSelect={setActiveSlot} 
        />
      </main>

      {activeSlot && (
        <SlotDetail 
          slot={activeSlot} 
          onClose={() => setActiveSlot(null)} 
          onSelectBooking={(token, bookingData) => {
            setActiveSlot({
              mode: 'booking',
              bookingId: token,
              time: bookingData.time || activeSlot.time,
              experienceName: bookingData.experienceName || activeSlot.experienceName,
              date: bookingData.date || activeSlot.date
            });
          }}
        />
      )}

      {showManualBooking && (
        <ManualBookingModal 
          onClose={() => setShowManualBooking(false)}
          onBookingSuccess={() => setRefreshKey(prev => prev + 1)}
          defaultDate={date}
        />
      )}
    </div>
  );
};

export default App;
