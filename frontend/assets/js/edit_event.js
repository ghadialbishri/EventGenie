document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('editEventForm');
  const eventId = document.getElementById('eventId').value;
  const btn = document.getElementById('editEventBtn');
  const spinner = btn.querySelector('.spinner-border');

  // Fetch event data and populate form
  fetch(`/api.php/events/${eventId}`)
    .then(response => {
      if (!response.ok) {
        if (response.status === 404) {
          throw new Error('Event not found');
        } else if (response.status === 403) {
          throw new Error('You do not have permission to view this event');
        } else {
          throw new Error('Failed to fetch event data');
        }
      }
      return response.json();
    })
    .then(event => {
      document.getElementById('eventType').value = event.event_type;
      document.getElementById('budget').value = event.budget;
      document.getElementById('numAttendees').value = event.number_of_attendees;
      document.getElementById('location').value = event.event_location;
      document.getElementById('duration').value = event.event_duration;
      document.getElementById('setupTime').value = event.setup_time;
      document.getElementById('purpose').value = event.event_purpose;
      document.getElementById('catering').checked = event.catering_required;
      document.getElementById('techReq').value = event.tech_requirements;
      document.getElementById('audienceType').value = event.audience_type;
      document.getElementById('season').value = event.season;
      document.getElementById('prevEventTime').value = event.previous_event_time || '';
      document.getElementById('venueSize').value = event.venue_size || '';
      document.getElementById('eventDate').value = event.event_date || '';
    })
    .catch(error => {
      alert('Error: ' + error.message);
      console.error('Error fetching event:', error);
    });

  // Handle form submission
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    btn.disabled = true;
    if (spinner) spinner.style.display = 'inline-block';

    const updatedEvent = {
      event_type: document.getElementById('eventType').value,
      budget: parseFloat(document.getElementById('budget').value),
      number_of_attendees: parseInt(document.getElementById('numAttendees').value),
      event_location: document.getElementById('location').value,
      event_duration: parseFloat(document.getElementById('duration').value),
      setup_time: parseFloat(document.getElementById('setupTime').value),
      event_purpose: document.getElementById('purpose').value,
      catering_required: document.getElementById('catering').checked,
      tech_requirements: document.getElementById('techReq').value,
      audience_type: document.getElementById('audienceType').value,
      season: document.getElementById('season').value,
      previous_event_time: parseFloat(document.getElementById('prevEventTime').value) || 0,
      venue_size: parseFloat(document.getElementById('venueSize').value),
      event_date: document.getElementById('eventDate').value,
    };

    fetch(`/api.php/events/${eventId}`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(updatedEvent)
    })
      .then(response => {
        if (!response.ok) {
          if (response.status === 400) {
            throw new Error('Invalid event data provided');
          } else if (response.status === 403) {
            throw new Error('You do not have permission to update this event');
          } else if (response.status === 404) {
            throw new Error('Event not found');
          } else {
            throw new Error('Failed to update event');
          }
        }
        return response.json();
      })
      .then(data => {
        alert('Event updated successfully!');
        window.location.href = 'view_events.html';
      })
      .catch(error => {
        alert('Error: ' + error.message);
        console.error('Error updating event:', error);
      })
      .finally(() => {
        btn.disabled = false;
        if (spinner) spinner.style.display = 'none';
      });
  });
});
