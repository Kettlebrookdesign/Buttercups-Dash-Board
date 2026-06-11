import React, { useState, useEffect, useRef } from 'react';
import { api } from '../services/api';

const Search = ({ onSelect }) => {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState([]);
  const [showDropdown, setShowDropdown] = useState(false);
  const [loading, setLoading] = useState(false);
  const dropdownRef = useRef(null);

  useEffect(() => {
    if (query.trim().length < 2) {
      setResults([]);
      setShowDropdown(false);
      return;
    }

    const timer = setTimeout(async () => {
      setLoading(true);
      setShowDropdown(true);
      try {
        const data = await api.search(query);
        setResults(data.result?.results || data.results || []);
      } catch (err) {
        console.error('Search failed:', err);
      }
      setLoading(false);
    }, 300);

    return () => clearTimeout(timer);
  }, [query]);

  // Click outside listener
  useEffect(() => {
    const handleClick = (e) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
        setShowDropdown(false);
      }
    };
    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, []);

  const handleSelect = (booking) => {
    onSelect(booking);
    setQuery('');
    setShowDropdown(false);
  };

  return (
    <div className="search-box" ref={dropdownRef}>
      <div className="search-input-field">
        <span className="search-icon">🔍</span>
        <input 
          type="text" 
          placeholder="Search name or reference..." 
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          onFocus={() => query.length >= 2 && setShowDropdown(true)}
        />
        {query && <button className="clear-btn" onClick={() => setQuery('')}>×</button>}
      </div>

      {showDropdown && (
        <div className="search-dropdown">
          {loading ? (
            <div className="dropdown-status">Searching...</div>
          ) : results.length === 0 ? (
            <div className="dropdown-status">No matches found for "{query}"</div>
          ) : (
            results.map((r, idx) => {
              const bookingDate = new Date(r.start_date);
              const formattedDate = bookingDate.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
              const formattedTime = bookingDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
              
              return (
                <div 
                  key={`${r.reference}-${idx}`} 
                  className="dropdown-item"
                  onClick={() => handleSelect(r)}
                >
                  <div className="item-row">
                    <span className="item-name">{r.customer_name}</span>
                    <span className="item-ref">#B-{r.reference}</span>
                  </div>
                  <div className="item-details">
                    <span className="item-exp">{r.experience}</span>
                    <span className="item-time">{formattedDate} at {formattedTime}</span>
                  </div>
                </div>
              );
            })
          )}
        </div>
      )}
    </div>
  );
};

export default Search;
