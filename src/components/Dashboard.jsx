import React, { useState, useEffect } from 'react';
import { api } from '../services/api';

const Dashboard = ({ selectedDate, onDateSelect, onSlotSelect }) => {
  const [experiences, setExperiences] = useState([]);
  const [teaSummary, setTeaSummary] = useState({ total_teas: 0, tea_breakdown: [] });
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    const fetchSummary = async () => {
      setLoading(true);
      try {
        const data = await api.getSummary(selectedDate);
        setExperiences(data.experiences || []);
        setTeaSummary(data.tea_summary || { total_teas: 0, tea_breakdown: [] });
      } catch (err) {
        console.error('Failed to fetch summary:', err);
      }
      setLoading(false);
    };
    fetchSummary();
  }, [selectedDate]);

  const quickFilters = [
    { label: 'Today', date: new Date().toISOString().split('T')[0] },
    { label: 'Tomorrow', date: new Date(Date.now() + 86400000).toISOString().split('T')[0] }
  ];

  const processedExperiences = experiences.map(exp => {
    // Group slots by time to handle multiple appointments at the same time
    const slotsMap = {};
    exp.slots.forEach(slot => {
      if (!slotsMap[slot.time]) {
        slotsMap[slot.time] = {
          time: slot.time,
          attendees: 0,
          capacity: 0,
          ids: []
        };
      }
      slotsMap[slot.time].attendees += slot.attendees;
      slotsMap[slot.time].capacity += (slot.capacity || 0);
      slotsMap[slot.time].ids.push(slot.appointment_id);
    });

    const timeSlots = Object.values(slotsMap).sort((a, b) => a.time.localeCompare(b.time));

    return {
      ...exp,
      timeSlots
    };
  });

  const getSlotStatus = (attendees, capacity) => {
    if (!capacity) return 'green';
    const ratio = attendees / capacity;
    if (ratio >= 1) return 'red';
    if (ratio >= 0.8) return 'amber';
    return 'green';
  };

  return (
    <section className="dashboard">
      <div className="filters-bar">
        <div className="quick-date-group">
          {quickFilters.map(f => (
            <button key={f.label} onClick={() => onDateSelect(f.date)} className={selectedDate === f.date ? 'active' : ''}>
              {f.label}
            </button>
          ))}
          <input type="date" value={selectedDate} onChange={(e) => onDateSelect(e.target.value)} className="date-picker-input" />
        </div>
        <div className="date-display">{new Date(selectedDate).toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' })}</div>
      </div>

      <div className="experience-grid">
        {teaSummary.total_teas > 0 && (
          <div className="experience-card summary-card tea-summary-card" style={{ borderLeft: '6px solid #d4a373', background: '#fffcf2' }}>
            <header className="card-header">
              <h3 className="experience-title" style={{ color: '#8b5e3c' }}>Daily Tea Summary</h3>
              <div className="total-badge" style={{ background: '#5d4037', boxShadow: '0 4px 12px rgba(93, 64, 55, 0.2)' }}>
                <span className="number" style={{ color: '#fff' }}>{teaSummary.total_teas}</span>
                <span className="label" style={{ color: '#e7d8c9', opacity: 0.9 }}>Total Teas</span>
              </div>
            </header>
            <div className="tea-breakdown-list" style={{ padding: '1rem' }}>
              {teaSummary.tea_breakdown.map((item, idx) => (
                <div key={idx} className="tea-breakdown-item" style={{ display: 'flex', justifyContent: 'space-between', padding: '0.6rem 0', borderBottom: idx < teaSummary.tea_breakdown.length - 1 ? '1px dashed #e7d8c9' : 'none' }}>
                  <span style={{ fontWeight: 600, color: '#5d4037' }}>{item.title}</span>
                  <span style={{ fontWeight: 800, color: '#8b5e3c' }}>x{item.quantity}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {teaSummary.tea_notes && teaSummary.tea_notes.length > 0 && (
          <div className="experience-card summary-card tea-notes-card" style={{ borderLeft: '6px solid #8b5e3c', background: '#fdfaf6' }}>
            <header className="card-header">
              <h3 className="experience-title" style={{ color: '#5d4037' }}>Tea Booking Notes</h3>
              <div className="total-badge" style={{ background: '#5d4037', boxShadow: '0 4px 12px rgba(93, 64, 55, 0.2)' }}>
                <span className="number" style={{ color: '#fff' }}>{teaSummary.tea_notes.length}</span>
                <span className="label" style={{ color: '#e7d8c9', opacity: 0.9 }}>With Notes</span>
              </div>
            </header>
            <div className="tea-notes-list" style={{ padding: '0 1rem 1rem 1rem' }}>
              {teaSummary.tea_notes.map((note, nIdx) => (
                <div 
                  key={nIdx} 
                  className="tea-note-item clickable" 
                  onClick={() => onSlotSelect({
                    mode: 'booking',
                    bookingId: note.booking_token,
                    time: note.time,
                    experienceName: note.service_name,
                    date: selectedDate
                  })}
                  style={{ 
                    padding: '1rem 0', 
                    borderBottom: nIdx < teaSummary.tea_notes.length - 1 ? '1px solid #e7d8c9' : 'none',
                    cursor: 'pointer'
                  }}
                >
                  <div className="note-meta" style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '0.4rem', fontSize: '0.85rem' }}>
                    <strong style={{ color: '#8b5e3c' }}>{note.time} • {note.customer_name}</strong>
                    <span style={{ color: 'var(--muted)', fontWeight: 500 }}>{note.service_name}</span>
                  </div>
                  <p className="note-text" style={{ margin: 0, fontSize: '0.9rem', color: '#5d4037', fontStyle: 'italic', display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>
                    "{note.note}"
                  </p>
                </div>
              ))}
            </div>
          </div>
        )}

        {loading ? <div className="loading">Loading bookings...</div> : processedExperiences.map(exp => (
          <div key={exp.id} className="experience-card">
            <header className="card-header">
              <h3 className="experience-title">{exp.name}</h3>
              <div className="total-badge">
                <span className="number">{exp.total_attendees}</span>
                <span className="label">Total Booked</span>
              </div>
            </header>
            
            <div className="slot-list">
              {exp.timeSlots.length > 0 ? exp.timeSlots.map(slot => {
                const status = getSlotStatus(slot.attendees, slot.capacity);
                return (
                  <div 
                    key={slot.time} 
                    className={`slot-row status-${status}`} 
                    onClick={() => {
                      console.log("Dashboard Trace: Clicking slot", slot.time, "with IDs:", slot.ids);
                      onSlotSelect({
                        ids: slot.ids,
                        time: slot.time,
                        experienceName: exp.name,
                        attendees: slot.attendees,
                        capacity: slot.capacity,
                        date: selectedDate
                      });
                    }}
                  >
                    <span className="slot-time">{slot.time}</span>
                    <span className="slot-info">
                      {slot.attendees} / {slot.capacity || '--'} booked
                    </span>
                    <span className="slot-arrow">→</span>
                  </div>
                );
              }) : <div className="no-bookings">No bookings for this date</div>}
            </div>
          </div>
        ))}
      </div>
    </section>
  );
};

export default Dashboard;
