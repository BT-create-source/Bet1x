const axios = require('axios');
require('dotenv').config();

async function test() {
  const today = new Date();
  const dateFrom = new Date(today);
  dateFrom.setDate(today.getDate() - 3);
  const dateTo = new Date(today);
  dateTo.setDate(today.getDate() + 3);

  const formatDate = (d) => d.toISOString().split('T')[0];

  const comps = 'PL,PD,SA,BL1,FL1,CL,ELC,DED,PPL,BSA,CLI';
  const url = `https://api.football-data.org/v4/matches?dateFrom=${formatDate(dateFrom)}&dateTo=${formatDate(dateTo)}&competitions=${comps}`;

  console.log('Fetching:', url);
  console.log('API Key:', process.env.FOOTBALL_API_KEY ? 'Present' : 'Missing');

  try {
    const response = await axios.get(url, {
      headers: {
        'X-Auth-Token': process.env.FOOTBALL_API_KEY
      }
    });
    console.log('Success!');
    console.log('Total matches fetched:', response.data.matches.length);
    if (response.data.matches.length > 0) {
      console.log('Sample match:', JSON.stringify(response.data.matches[0], null, 2));
    }
  } catch (error) {
    console.error('Error fetching data:', error.response ? error.response.data : error.message);
  }
}

test();
